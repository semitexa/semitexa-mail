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
use Semitexa\Orm\Application\Service\Uuid7;

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

        // Persist mail message (rendered bodies stored before queue dispatch).
        // Resources are readonly: save() returns the persisted row (with the
        // engine-generated UUID) — always continue with the RETURNED instance.
        $mailMessage = $this->mailRepository->save(new MailMessageResource(
            tenant_id: $envelope->tenantId,
            status: MailMessageStatus::Pending->value,
            driver: $config->driver,
            template_handle: $envelope->templateHandle,
            from_email: $fromEmail,
            from_name: $fromName,
            reply_to: $replyTo,
            to_json: json_encode($this->recipientsToArray($envelope->to), JSON_THROW_ON_ERROR),
            cc_json: $envelope->cc !== []
                ? json_encode($this->recipientsToArray($envelope->cc), JSON_THROW_ON_ERROR) : null,
            bcc_json: $envelope->bcc !== []
                ? json_encode($this->recipientsToArray($envelope->bcc), JSON_THROW_ON_ERROR) : null,
            subject: $subject,
            html_body: $htmlBody,
            text_body: $textBody,
            headers_json: $envelope->headers !== []
                ? json_encode($envelope->headers, JSON_THROW_ON_ERROR) : null,
            tags_json: $envelope->tags !== []
                ? json_encode($envelope->tags, JSON_THROW_ON_ERROR) : null,
            metadata_json: $envelope->metadata !== []
                ? json_encode($envelope->metadata, JSON_THROW_ON_ERROR) : null,
            attachments_json: $envelope->attachments !== []
                ? json_encode(array_map($this->serializeAttachment(...), $envelope->attachments), JSON_THROW_ON_ERROR)
                : null,
            idempotency_key: $envelope->idempotencyKey,
        ));
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

            $this->mailRepository->save($mailMessage->copyWith([
                'status' => MailMessageStatus::Queued->value,
            ]));

            return new MailDispatchResult(
                status:    MailDispatchStatus::Queued,
                messageId: $messageId,
            );
        } catch (\Throwable $e) {
            $this->mailRepository->save($mailMessage->copyWith([
                'status' => MailMessageStatus::EnqueueFailed->value,
                'error_code' => MailErrorCode::QueueUnavailable->value,
                'error_message' => $e->getMessage(),
            ]));

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
        $mailMessage = $this->mailRepository->save($mailMessage->copyWith([
            'status' => MailMessageStatus::Sending->value,
            'last_attempt_at' => new \DateTimeImmutable(),
        ]));

        try {
            $resolvedAttachments = $this->attachmentResolver->resolve($attachmentRefs);
        } catch (\Throwable $e) {
            $this->mailRepository->save($mailMessage->copyWith([
                'status' => MailMessageStatus::Failed->value,
                'error_code' => MailErrorCode::AttachmentMissing->value,
                'error_message' => $e->getMessage(),
            ]));

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
        $this->attemptRepository->save(new MailAttemptResource(
            tenant_id: $mailMessage->tenant_id,
            mail_message_id: Uuid7::toBytes($messageId),
            attempt_no: $attemptNo,
            driver: $config->driver,
            status: $result->status->value,
            started_at: $startedAt,
            finished_at: $finishedAt,
            provider_message_id: $result->providerMessageId,
            provider_status: $result->providerStatus,
            provider_response_json: $result->providerResponse !== []
                ? json_encode($result->providerResponse, JSON_THROW_ON_ERROR) : null,
            error_code: $result->errorCode,
            error_message: $result->errorMessage,
        ));

        // Update mail message status
        $finalStatus = match ($result->status) {
            MailTransportStatus::Accepted => MailMessageStatus::Sent,
            MailTransportStatus::Deferred => MailMessageStatus::Deferred,
            default                       => MailMessageStatus::Failed,
        };

        $this->mailRepository->save($mailMessage->copyWith([
            'status' => $finalStatus->value,
            'provider_message_id' => $result->providerMessageId,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
        ]));

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
