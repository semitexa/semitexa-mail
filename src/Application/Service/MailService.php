<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Queue\DelayedQueueTransportInterface;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Orm\OrmManager;
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

    /**
     * The connection mail_messages lives on: its transaction decides when a
     * mail may leave. Optional only for a MailService built by hand in a test.
     */
    #[InjectAsReadonly(optional: true)]
    protected OrmManager $orm;

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

        // The same key is the same mail: it is never stored twice.
        if ($envelope->idempotencyKey !== null) {
            $existing = $this->mailRepository->findByIdempotencyKey($envelope->tenantId, $envelope->idempotencyKey);
            if ($existing !== null) {
                return $this->again($existing, $envelope, $options, $config);
            }
        }

        // A mail to nobody reached the SMTP server, was refused there, and was
        // retried as a server error.
        // After the key lookup: a resend sends the stored recipients.
        $recipients = array_filter([...$envelope->to, ...$envelope->cc, ...$envelope->bcc], static fn (MailRecipient $r): bool => trim($r->email) !== '');
        if ($recipients === []) {
            return new MailDispatchResult(
                status:       MailDispatchStatus::Failed,
                errorCode:    MailErrorCode::InvalidRecipient->value,
                errorMessage: 'The mail has no recipient: to, cc and bcc are all empty.',
            );
        }

        // The inline limit was checked only at delivery, after the mail was
        // stored and queued, and failed there as a missing attachment.
        foreach ($envelope->attachments as $attachment) {
            if ($attachment->inlineContents !== null && strlen($attachment->inlineContents) > AttachmentReference::MAX_INLINE_SIZE) {
                return new MailDispatchResult(
                    status:       MailDispatchStatus::Failed,
                    errorCode:    MailErrorCode::AttachmentMissing->value,
                    errorMessage: "Inline attachment '{$attachment->filename}' exceeds the 256 KB size limit: use a storage-backed reference for large files.",
                );
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
        try {
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
            mailerKey: $envelope->mailerKey,
            replyToName: $envelope->replyTo !== null ? $envelope->replyTo->name : null,
            ));
        } catch (ConstraintViolationException $e) {
            // A concurrent send() with the same key stored it first.
            $existing = $envelope->idempotencyKey !== null
                ? $this->mailRepository->findByIdempotencyKey($envelope->tenantId, $envelope->idempotencyKey)
                : null;
            if ($existing === null) {
                throw $e;
            }

            return $this->again($existing, $envelope, $options, $config);
        }

        return $this->deliverOrQueue($mailMessage, $options, $config);
    }

    /**
     * A send() whose key is already stored. Sent answers sent and one still on
     * its way answers queued; one that failed, was deferred or never reached
     * the queue is sent again from its stored row. It used to insert a second
     * row, which the unique index refused, and send() threw: a failed mail
     * could not be retried under its key.
     */
    private function again(MailMessage $existing, MailEnvelope $envelope, MailSendOptions $options, MailerConfig $config): MailDispatchResult
    {
        $id = $existing->getId();
        // The row's mailer, as the worker resolves it: a resend sent now and
        // one queued could otherwise go through different mailers.
        if ($existing->getMailerKey() !== $envelope->mailerKey) {
            try {
                $config = $this->configResolver->resolve($existing->getTenantId(), $existing->getMailerKey());
            } catch (\Throwable $e) {
                return new MailDispatchResult(status: MailDispatchStatus::Failed, messageId: $id, errorCode: MailErrorCode::ConfigError->value, errorMessage: $e->getMessage());
            }
        }

        return match ($existing->getStatus()) {
            MailMessageStatus::Sent->value => new MailDispatchResult(status: MailDispatchStatus::Sent, messageId: $id),
            MailMessageStatus::Pending->value,
            MailMessageStatus::Queued->value,
            MailMessageStatus::Sending->value => new MailDispatchResult(status: MailDispatchStatus::Queued, messageId: $id),
            // Deferred has a retry on the queue already, unless the queue is
            // this process's own: another one would run a second chain, and
            // two workers would both deliver.
            MailMessageStatus::Deferred->value => $this->queueIsProcessLocal()
                ? $this->deliverOrQueue($existing, $options, $config)
                : new MailDispatchResult(status: MailDispatchStatus::Queued, messageId: $id),
            default => in_array($existing->getErrorCode(), [MailErrorCode::InvalidRecipient->value, MailErrorCode::Provider5xx->value], true)
                // Refused for good by the server: sending it again is refused again.
                ? new MailDispatchResult(status: MailDispatchStatus::Failed, messageId: $id, errorCode: $existing->getErrorCode(), errorMessage: $existing->getErrorMessage())
                : $this->deliverOrQueue($existing->with(['errorCode' => null, 'errorMessage' => null]), $options, $config),
        };
    }

    /**
     * Inside the caller's transaction the stored row is part of it, and the
     * mail leaves only once it commits. Sent at once (no async events) or
     * published at once, a rolled-back order still mailed its customer, and
     * runWithRetry() replaying the unit mailed them twice: the rollback had
     * taken the idempotency key with it.
     */
    private function deliverOrQueue(MailMessage $mailMessage, MailSendOptions $options, MailerConfig $config): MailDispatchResult
    {
        $transaction = isset($this->orm) ? $this->orm->getTransactionManager() : null;
        if ($transaction !== null && $transaction->isActive()) {
            // A resend of a failed row: pending inside the transaction, so a
            // second send() of the key before the commit answers Queued
            // instead of holding back a second delivery.
            if ($mailMessage->getStatus() !== MailMessageStatus::Pending->value) {
                $mailMessage = $this->mailRepository->save($mailMessage->with(['status' => MailMessageStatus::Pending->value]));
            }
            $transaction->afterCommit(function () use ($mailMessage, $options, $config): void {
                // The caller was told Queued: a failure now is in the row, and here.
                $result = $this->deliverNow($mailMessage, $options, $config);
                if (!$result->succeeded()) {
                    StaticLoggerBridge::error('mail', 'A mail sent inside a transaction failed after the commit.', [
                        'message_id' => $result->messageId,
                        'status' => $result->status->value,
                        'error_code' => $result->errorCode,
                    ]);
                }
            });

            return new MailDispatchResult(status: MailDispatchStatus::Queued, messageId: $mailMessage->getId());
        }

        return $this->deliverNow($mailMessage, $options, $config);
    }

    private function deliverNow(MailMessage $mailMessage, MailSendOptions $options, MailerConfig $config): MailDispatchResult
    {
        if ($options->mode === MailSendMode::Queued && !$this->queueIsProcessLocal()) {
            return $this->dispatchToQueue($mailMessage, $mailMessage->getId(), $config->queue);
        }

        // The stored attachments, as the worker reads them: a re-send carries
        // the row's, not whatever the new envelope holds.
        $attachments = array_map(AttachmentReference::fromStored(...), $mailMessage->getAttachments());

        return $this->sendSync($mailMessage, $mailMessage->getId(), $attachments, $config);
    }

    /**
     * Without async events the queue is in-memory, one per process, and no
     * mail:work can read it: a queued mail is sent at once instead. A transport
     * that cannot be resolved is left to dispatchToQueue(), which reports it.
     */
    private function queueIsProcessLocal(): bool
    {
        try {
            return QueueConfig::isProcessLocal(QueueConfig::defaultTransport());
        } catch (\Throwable) {
            return false;
        }
    }

    private function dispatchToQueue(
        MailMessage $mailMessage,
        string $messageId,
        string $queueName,
    ): MailDispatchResult {
        try {
            $transport    = QueueTransportRegistry::create(QueueConfig::defaultTransport());
            $queueMessage = new QueuedMailMessage(messageId: $messageId);
            // Queued before the publish: saved after, it could overwrite the
            // `sent` a fast worker had already written.
            $mailMessage = $this->mailRepository->save($mailMessage->with([
                'status' => MailMessageStatus::Queued->value,
            ]));
            $transport->publish($queueName, $queueMessage->toJson());

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
        $prepared->replyTo     = $mailMessage->getReplyTo() !== null ? new MailRecipient($mailMessage->getReplyTo(), $mailMessage->getReplyToName()) : null;
        $prepared->to          = $this->arrayToRecipients($mailMessage->getTo());
        $prepared->cc          = $this->arrayToRecipients($mailMessage->getCc());
        $prepared->bcc         = $this->arrayToRecipients($mailMessage->getBcc());
        $prepared->subject     = $mailMessage->getSubject();
        $prepared->htmlBody    = $mailMessage->getHtmlBody();
        $prepared->textBody    = $mailMessage->getTextBody();
        $prepared->headers     = $mailMessage->getHeaders();
        $prepared->attachments = $resolvedAttachments;

        // An unknown MAIL_DRIVER threw out of send() and left the row
        // `sending` for good.
        try {
            $transport = MailTransportRegistry::get($config->driver);
        } catch (\InvalidArgumentException $e) {
            $this->mailRepository->save($mailMessage->with([
                'status' => MailMessageStatus::Failed->value,
                'errorCode' => MailErrorCode::ConfigError->value,
                'errorMessage' => $e->getMessage(),
            ]));

            return new MailDispatchResult(
                status:       MailDispatchStatus::Failed,
                messageId:    $messageId,
                errorCode:    MailErrorCode::ConfigError->value,
                errorMessage: $e->getMessage(),
            );
        }
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

        // A temporary failure of a mail sent at once was never tried again:
        // the row stayed `deferred` for good. With a queue a worker reads, the
        // retry goes there, as the worker's own retries do.
        if ($finalStatus === MailMessageStatus::Deferred && !$this->queueIsProcessLocal()) {
            $retry = new QueuedMailMessage(messageId: $messageId, attempts: 1);
            try {
                $queue = QueueTransportRegistry::create(QueueConfig::defaultTransport());
                if ($queue instanceof DelayedQueueTransportInterface && $retry->retryDelay > 0) {
                    $queue->publishDelayed($config->queue, $retry->toJson(), $retry->retryDelay);
                } else {
                    $queue->publish($config->queue, $retry->toJson());
                }

                return new MailDispatchResult(
                    status:       MailDispatchStatus::Queued,
                    messageId:    $messageId,
                    errorCode:    $result->errorCode,
                    errorMessage: $result->errorMessage,
                );
            } catch (\Throwable $e) {
                // Left deferred, the next send() under its key would answer
                // queued and nothing would ever deliver it.
                $this->mailRepository->save($mailMessage->with([
                    'status' => MailMessageStatus::EnqueueFailed->value,
                    'errorCode' => MailErrorCode::QueueUnavailable->value,
                    'errorMessage' => "The retry could not be queued: {$e->getMessage()}",
                ]));
            }
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
