<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Mail\Application\Db\MySQL\Model\MailAttemptResource;
use Semitexa\Mail\Application\Db\MySQL\Model\MailMessageResource;
use Semitexa\Mail\Domain\Contract\MailAttemptRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailServiceInterface;
use Semitexa\Mail\Domain\Contract\MailTemplateRendererInterface;
use Semitexa\Mail\Domain\Contract\MailerConfigResolverInterface;
use Semitexa\Mail\Domain\Model\QueuedMailMessage;
use Semitexa\Mail\Application\Service\MailTransportRegistry;
use Semitexa\Mail\Domain\Model\AttachmentReference;
use Semitexa\Mail\Domain\Model\MailDispatchResult;
use Semitexa\Mail\Domain\Enum\MailDispatchStatus;
use Semitexa\Mail\Domain\Enum\MailErrorCode;
use Semitexa\Mail\Domain\Model\MailEnvelope;
use Semitexa\Mail\Domain\Enum\MailMessageStatus;
use Semitexa\Mail\Domain\Model\MailRecipient;
use Semitexa\Mail\Domain\Enum\MailSendMode;
use Semitexa\Mail\Domain\Model\MailSendOptions;
use Semitexa\Mail\Domain\Enum\MailTransportStatus;
use Semitexa\Mail\Domain\Model\MailerConfig;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;
use Semitexa\Orm\Uuid\Uuid7;

#[SatisfiesServiceContract(of: MailServiceInterface::class)]
final class MailService implements MailServiceInterface
{
    #[InjectAsReadonly]
    protected MailerConfigResolverInterface $configResolver;

    #[InjectAsReadonly]
    protected MailTemplateRendererInterface $renderer;

    #[InjectAsReadonly]
    protected MailRepositoryInterface $mailRepository;

    #[InjectAsReadonly]
    protected MailAttemptRepositoryInterface $attemptRepository;

    #[InjectAsReadonly]
    protected AttachmentResolver $attachmentResolver;

    public function send(MailEnvelope $envelope, ?MailSendOptions $options = null): MailDispatchResult
    {
        $options ??= new MailSendOptions();

        try {
            $config = $this->configResolver->resolve($envelope->tenantId, $envelope->mailerKey);
        } catch (\Throwable $e) {
            return new MailDispatchResult(
                status:       MailDispatchStatus::Failed,
                errorCode:    MailErrorCode::ConfigError->value,
                errorMessage: $e->getMessage(),
            );
        }

        // Short-circuit on idempotency key when an active job already exists
        if ($envelope->idempotencyKey !== null && $envelope->tenantId !== null) {
            $existing = $this->mailRepository->findByIdempotencyKey(
                $envelope->tenantId,
                $envelope->idempotencyKey,
            );
            if ($existing !== null) {
                $activeStatuses = [
                    MailMessageStatus::Queued->value,
                    MailMessageStatus::Sending->value,
                    MailMessageStatus::Sent->value,
                ];
                if (in_array($existing->status, $activeStatuses, true)) {
                    return new MailDispatchResult(
                        status:    MailDispatchStatus::Queued,
                        messageId: $existing->id,
                    );
                }
            }
        }

        // Render template or use inline bodies
        $subject  = $envelope->subject ?? '';
        $htmlBody = $envelope->htmlBody;
        $textBody = $envelope->textBody;

        if ($envelope->templateHandle !== null) {
            try {
                $rendered = $this->renderer->render(
                    $envelope->templateHandle,
                    $envelope->variables,
                    $envelope->locale,
                );
                $subject  = $rendered['subject'];
                $htmlBody = $rendered['htmlBody'];
                $textBody = $rendered['textBody'];
            } catch (\Throwable $e) {
                return new MailDispatchResult(
                    status:       MailDispatchStatus::Failed,
                    errorCode:    MailErrorCode::RenderFailed->value,
                    errorMessage: $e->getMessage(),
                );
            }
        }

        // Resolve effective from / reply-to
        $fromEmail = $envelope->from?->email ?? $config->fromEmail;
        $fromName  = $envelope->from?->name  ?? $config->fromName;
        $replyTo   = $envelope->replyTo?->email ?? $config->replyTo;

        // Persist mail message (rendered bodies stored before queue dispatch)
        $mailMessage = new MailMessageResource();
        $mailMessage->tenant_id      = $envelope->tenantId;
        $mailMessage->status         = MailMessageStatus::Pending->value;
        $mailMessage->driver         = $config->driver;
        $mailMessage->template_handle = $envelope->templateHandle;
        $mailMessage->from_email     = $fromEmail;
        $mailMessage->from_name      = $fromName;
        $mailMessage->reply_to       = $replyTo;
        $mailMessage->to_json        = json_encode($this->recipientsToArray($envelope->to), JSON_THROW_ON_ERROR);
        $mailMessage->cc_json        = $envelope->cc !== []
            ? json_encode($this->recipientsToArray($envelope->cc), JSON_THROW_ON_ERROR) : null;
        $mailMessage->bcc_json       = $envelope->bcc !== []
            ? json_encode($this->recipientsToArray($envelope->bcc), JSON_THROW_ON_ERROR) : null;
        $mailMessage->subject        = $subject;
        $mailMessage->html_body      = $htmlBody;
        $mailMessage->text_body      = $textBody;
        $mailMessage->headers_json   = $envelope->headers !== []
            ? json_encode($envelope->headers, JSON_THROW_ON_ERROR) : null;
        $mailMessage->tags_json      = $envelope->tags !== []
            ? json_encode($envelope->tags, JSON_THROW_ON_ERROR) : null;
        $mailMessage->metadata_json  = $envelope->metadata !== []
            ? json_encode($envelope->metadata, JSON_THROW_ON_ERROR) : null;
        $mailMessage->attachments_json = $envelope->attachments !== []
            ? json_encode(array_map($this->serializeAttachment(...), $envelope->attachments), JSON_THROW_ON_ERROR)
            : null;
        $mailMessage->idempotency_key = $envelope->idempotencyKey;

        $this->mailRepository->save($mailMessage);
        // $mailMessage->id is now set to a UUID string by ensureUuid()
        $messageId = $mailMessage->id;

        if ($options->mode === MailSendMode::Queued) {
            return $this->dispatchToQueue($mailMessage, $messageId, $config->queue);
        }

        return $this->sendSync($mailMessage, $messageId, $envelope->attachments, $config);
    }

    private function dispatchToQueue(
        MailMessageResource $mailMessage,
        string $messageId,
        string $queueName,
    ): MailDispatchResult {
        try {
            $transport    = QueueTransportRegistry::create(QueueConfig::defaultTransport());
            $queueMessage = new QueuedMailMessage(messageId: $messageId);
            $transport->publish($queueName, $queueMessage->toJson());

            $mailMessage->status = MailMessageStatus::Queued->value;
            $this->mailRepository->save($mailMessage);

            return new MailDispatchResult(
                status:    MailDispatchStatus::Queued,
                messageId: $messageId,
            );
        } catch (\Throwable $e) {
            $mailMessage->status        = MailMessageStatus::EnqueueFailed->value;
            $mailMessage->error_code    = MailErrorCode::QueueUnavailable->value;
            $mailMessage->error_message = $e->getMessage();
            $this->mailRepository->save($mailMessage);

            return new MailDispatchResult(
                status:       MailDispatchStatus::EnqueueFailed,
                messageId:    $messageId,
                errorCode:    MailErrorCode::QueueUnavailable->value,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * @param list<AttachmentReference> $attachmentRefs
     */
    private function sendSync(
        MailMessageResource $mailMessage,
        string $messageId,
        array $attachmentRefs,
        MailerConfig $config,
    ): MailDispatchResult {
        $mailMessage->status          = MailMessageStatus::Sending->value;
        $mailMessage->last_attempt_at = new \DateTimeImmutable();
        $this->mailRepository->save($mailMessage);

        try {
            $resolvedAttachments = $this->attachmentResolver->resolve($attachmentRefs);
        } catch (\Throwable $e) {
            $mailMessage->status        = MailMessageStatus::Failed->value;
            $mailMessage->error_code    = MailErrorCode::AttachmentMissing->value;
            $mailMessage->error_message = $e->getMessage();
            $this->mailRepository->save($mailMessage);

            return new MailDispatchResult(
                status:       MailDispatchStatus::Failed,
                messageId:    $messageId,
                errorCode:    MailErrorCode::AttachmentMissing->value,
                errorMessage: $e->getMessage(),
            );
        }

        // Reconstruct recipients from stored JSON
        $toRecipients  = $this->arrayToRecipients(json_decode($mailMessage->to_json, true));
        $ccRecipients  = $mailMessage->cc_json  !== null ? $this->arrayToRecipients(json_decode($mailMessage->cc_json, true))  : [];
        $bccRecipients = $mailMessage->bcc_json !== null ? $this->arrayToRecipients(json_decode($mailMessage->bcc_json, true)) : [];

        $prepared              = new PreparedMailMessage();
        $prepared->messageId   = $messageId;
        $prepared->from        = new MailRecipient($mailMessage->from_email, $mailMessage->from_name);
        $prepared->replyTo     = $mailMessage->reply_to !== null ? new MailRecipient($mailMessage->reply_to) : null;
        $prepared->to          = $toRecipients;
        $prepared->cc          = $ccRecipients;
        $prepared->bcc         = $bccRecipients;
        $prepared->subject     = $mailMessage->subject;
        $prepared->htmlBody    = $mailMessage->html_body;
        $prepared->textBody    = $mailMessage->text_body;
        $prepared->headers     = $mailMessage->headers_json !== null ? json_decode($mailMessage->headers_json, true) : [];
        $prepared->attachments = $resolvedAttachments;

        $transport  = MailTransportRegistry::get($config->driver);
        $startedAt  = new \DateTimeImmutable();
        $result     = $transport->deliver($prepared, $config);
        $finishedAt = new \DateTimeImmutable();

        // Record attempt
        $attemptNo          = $this->attemptRepository->countByMessageId($messageId) + 1;
        $attempt            = new MailAttemptResource();
        $attempt->tenant_id = $mailMessage->tenant_id;
        $attempt->mail_message_id     = Uuid7::toBytes($messageId);
        $attempt->attempt_no          = $attemptNo;
        $attempt->driver              = $config->driver;
        $attempt->status              = $result->status->value;
        $attempt->started_at          = $startedAt;
        $attempt->finished_at         = $finishedAt;
        $attempt->provider_message_id = $result->providerMessageId;
        $attempt->provider_status     = $result->providerStatus;
        $attempt->provider_response_json = $result->providerResponse !== []
            ? json_encode($result->providerResponse, JSON_THROW_ON_ERROR) : null;
        $attempt->error_code    = $result->errorCode;
        $attempt->error_message = $result->errorMessage;
        $this->attemptRepository->save($attempt);

        // Update mail message status
        $finalStatus = match ($result->status) {
            MailTransportStatus::Accepted => MailMessageStatus::Sent,
            MailTransportStatus::Deferred => MailMessageStatus::Deferred,
            default                       => MailMessageStatus::Failed,
        };

        $mailMessage->status              = $finalStatus->value;
        $mailMessage->provider_message_id = $result->providerMessageId;
        $mailMessage->error_code          = $result->errorCode;
        $mailMessage->error_message       = $result->errorMessage;
        $this->mailRepository->save($mailMessage);

        if ($result->status === MailTransportStatus::Accepted) {
            return new MailDispatchResult(
                status:    MailDispatchStatus::Sent,
                messageId: $messageId,
            );
        }

        return new MailDispatchResult(
            status:       MailDispatchStatus::Failed,
            messageId:    $messageId,
            errorCode:    $result->errorCode,
            errorMessage: $result->errorMessage,
        );
    }

    // -------------------------------------------------------------------------
    // Serialization helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<MailRecipient> $recipients
     * @return list<array{email: string, name: string|null}>
     */
    private function recipientsToArray(array $recipients): array
    {
        return array_map(
            fn(MailRecipient $r) => ['email' => $r->email, 'name' => $r->name],
            $recipients,
        );
    }

    /**
     * @param list<array{email: string, name?: string|null}> $data
     * @return list<MailRecipient>
     */
    private function arrayToRecipients(array $data): array
    {
        return array_map(
            fn(array $r) => new MailRecipient($r['email'], $r['name'] ?? null),
            $data,
        );
    }

    /**
     * Serialize an AttachmentReference for JSON storage.
     * Inline contents are base64-encoded so they survive JSON round-trips safely.
     *
     * @return array<string, mixed>
     */
    private function serializeAttachment(AttachmentReference $ref): array
    {
        $data = [
            'filename'    => $ref->filename,
            'mimeType'    => $ref->mimeType,
            'disposition' => $ref->disposition,
            'contentId'   => $ref->contentId,
            'storagePath' => $ref->storagePath,
            'sizeHint'    => $ref->sizeHint,
        ];

        if ($ref->inlineContents !== null) {
            $data['inlineContentsBase64'] = base64_encode($ref->inlineContents);
        }

        return $data;
    }
}
