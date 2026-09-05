<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Mail\Domain\Model\MailAttempt;
use Semitexa\Mail\Domain\Model\MailMessage;
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
                if (in_array($existing->getStatus(), $activeStatuses, true)) {
                    return new MailDispatchResult(
                        status:    MailDispatchStatus::Queued,
                        messageId: $existing->getId(),
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
        $mailMessage = $this->mailRepository->save(new MailMessage(
            id: '',
            tenantId: $envelope->tenantId,
            status: MailMessageStatus::Pending->value,
            driver: $config->driver,
            fromEmail: $fromEmail,
            subject: $subject,
            to: $this->recipientsToArray($envelope->to),
            cc: $this->recipientsToArray($envelope->cc),
            bcc: $this->recipientsToArray($envelope->bcc),
            templateHandle: $envelope->templateHandle,
            fromName: $fromName,
            replyTo: $replyTo,
            htmlBody: $htmlBody,
            textBody: $textBody,
            headers: $envelope->headers,
            tags: $envelope->tags,
            metadata: $envelope->metadata,
            attachments: array_map($this->serializeAttachment(...), $envelope->attachments),
            idempotencyKey: $envelope->idempotencyKey,
        ));
        $messageId = $mailMessage->getId();

        if ($options->mode === MailSendMode::Queued) {
            return $this->dispatchToQueue($mailMessage, $messageId, $config->queue);
        }

        return $this->sendSync($mailMessage, $messageId, $envelope->attachments, $config);
    }

    private function dispatchToQueue(
        MailMessage $mailMessage,
        string $messageId,
        string $queueName,
    ): MailDispatchResult {
        try {
            $transport    = QueueTransportRegistry::create(QueueConfig::defaultTransport());
            $queueMessage = new QueuedMailMessage(messageId: $messageId);
            $transport->publish($queueName, $queueMessage->toJson());

            $this->mailRepository->save($mailMessage->with([
                'status' => MailMessageStatus::Queued->value,
            ]));

            return new MailDispatchResult(
                status:    MailDispatchStatus::Queued,
                messageId: $messageId,
            );
        } catch (\Throwable $e) {
            $this->mailRepository->save($mailMessage->with([
                'status' => MailMessageStatus::EnqueueFailed->value,
                'errorCode' => MailErrorCode::QueueUnavailable->value,
                'errorMessage' => $e->getMessage(),
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
        MailMessage $mailMessage,
        string $messageId,
        array $attachmentRefs,
        MailerConfig $config,
    ): MailDispatchResult {
        $mailMessage = $this->mailRepository->save($mailMessage->with([
            'status' => MailMessageStatus::Sending->value,
            'lastAttemptAt' => new \DateTimeImmutable(),
        ]));

        try {
            $resolvedAttachments = $this->attachmentResolver->resolve($attachmentRefs);
        } catch (\Throwable $e) {
            $this->mailRepository->save($mailMessage->with([
                'status' => MailMessageStatus::Failed->value,
                'errorCode' => MailErrorCode::AttachmentMissing->value,
                'errorMessage' => $e->getMessage(),
            ]));

            return new MailDispatchResult(
                status:       MailDispatchStatus::Failed,
                messageId:    $messageId,
                errorCode:    MailErrorCode::AttachmentMissing->value,
                errorMessage: $e->getMessage(),
            );
        }

        $prepared              = new PreparedMailMessage();
        $prepared->messageId   = $messageId;
        $prepared->from        = new MailRecipient($mailMessage->getFromEmail(), $mailMessage->getFromName());
        $prepared->replyTo     = $mailMessage->getReplyTo() !== null ? new MailRecipient($mailMessage->getReplyTo()) : null;
        $prepared->to          = $this->arrayToRecipients($mailMessage->getTo());
        $prepared->cc          = $this->arrayToRecipients($mailMessage->getCc());
        $prepared->bcc         = $this->arrayToRecipients($mailMessage->getBcc());
        $prepared->subject     = $mailMessage->getSubject();
        $prepared->htmlBody    = $mailMessage->getHtmlBody();
        $prepared->textBody    = $mailMessage->getTextBody();
        $prepared->headers     = $mailMessage->getHeaders();
        $prepared->attachments = $resolvedAttachments;

        $transport  = MailTransportRegistry::get($config->driver);
        $startedAt  = new \DateTimeImmutable();
        $result     = $transport->deliver($prepared, $config);
        $finishedAt = new \DateTimeImmutable();

        // Record attempt
        $attemptNo          = $this->attemptRepository->countByMessageId($messageId) + 1;
        $this->attemptRepository->save(new MailAttempt(
            id: '',
            tenantId: $mailMessage->getTenantId(),
            mailMessageId: $messageId,
            attemptNo: $attemptNo,
            driver: $config->driver,
            status: $result->status->value,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            providerMessageId: $result->providerMessageId,
            providerStatus: $result->providerStatus,
            providerResponse: $result->providerResponse,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
        ));

        // Update mail message status
        $finalStatus = match ($result->status) {
            MailTransportStatus::Accepted => MailMessageStatus::Sent,
            MailTransportStatus::Deferred => MailMessageStatus::Deferred,
            default                       => MailMessageStatus::Failed,
        };

        $this->mailRepository->save($mailMessage->with([
            'status' => $finalStatus->value,
            'providerMessageId' => $result->providerMessageId,
            'errorCode' => $result->errorCode,
            'errorMessage' => $result->errorMessage,
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
