<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Feature\CurrentFeatureFlags;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Queue\DelayedQueueTransportInterface;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Orm\OrmManager;
use Semitexa\Mail\Domain\Model\MailAttempt;
use Semitexa\Mail\Domain\Model\MailMessage;
use Semitexa\Mail\Domain\Model\SensitiveMailBodies;
use Semitexa\Mail\Domain\Contract\MailAttemptRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailRecoveryInterface;
use Semitexa\Mail\Domain\Contract\MailResendClaimInterface;
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
    /** How long a pending or sending row may sit before a resend under its key takes it over. */
    public const int ABANDONED_AFTER_SECONDS = 900;

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
            $existing = $this->mailRepository->findByIdempotencyKey($envelope->tenantId ?? '', $envelope->idempotencyKey);
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
                // feature() in the template answers for the mail's site, and
                // for no user: a queue worker has no tenant, and answered with
                // every site's; inside a request it answered for the sender.
                $render = fn (): array => $this->renderer->render(
                    $envelope->templateHandle,
                    $envelope->variables,
                    $envelope->locale,
                );
                $rendered = CurrentFeatureFlags::forSubjectDuring($envelope->tenantId, null, $render);
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

        // Past a column's length, or not UTF-8, a strict MySQL refused the
        // insert and send() threw, where every other bad mail answers Failed.
        $unstorable = self::unstorable([
            'subject' => [$subject, 998],
            'tenantId' => [$envelope->tenantId, 64],
            'templateHandle' => [$envelope->templateHandle, 255],
            'from' => [$fromEmail, 255],
            'fromName' => [$fromName, 255],
            'replyTo' => [$replyTo, 255],
            'replyToName' => [$envelope->replyTo?->name, 255],
            'idempotencyKey' => [$envelope->idempotencyKey, 255],
            'mailerKey' => [$envelope->mailerKey, 128],
        ]);
        // A recipient's name goes into the row's JSON, whose encoding threw
        // a JsonException out of send() on a name that is not UTF-8.
        foreach (['to' => $envelope->to, 'cc' => $envelope->cc, 'bcc' => $envelope->bcc] as $field => $list) {
            foreach ($list as $recipient) {
                if ($unstorable === null && $recipient->name !== null && !mb_check_encoding($recipient->name, 'UTF-8')) {
                    $unstorable = sprintf("The name of %s recipient %s is not valid UTF-8.", $field, $recipient->email);
                }
            }
        }
        if ($unstorable !== null) {
            return new MailDispatchResult(
                status:       MailDispatchStatus::Failed,
                errorCode:    MailErrorCode::InvalidMessage->value,
                errorMessage: $unstorable,
            );
        }

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
            metadata: $envelope->sensitive ? [SensitiveMailBodies::METADATA_KEY => true] + $envelope->metadata : $envelope->metadata,
            attachments: array_map($this->serializeAttachment(...), $envelope->attachments),
            idempotencyKey: $envelope->idempotencyKey,
            mailerKey: $envelope->mailerKey,
            replyToName: $envelope->replyTo !== null ? $envelope->replyTo->name : null,
            ));
        } catch (ConstraintViolationException $e) {
            // A concurrent send() with the same key stored it first.
            $existing = $envelope->idempotencyKey !== null
                ? $this->mailRepository->findByIdempotencyKey($envelope->tenantId ?? '', $envelope->idempotencyKey)
                : null;
            if ($existing === null) {
                throw $e;
            }

            return $this->again($existing, $envelope, $options, $config);
        }

        return $this->deliverOrQueue($mailMessage, $options, $config);
    }

    /**
     * The first field mail_messages cannot hold: longer than its column (in
     * characters, as utf8mb4 counts them) or not UTF-8.
     *
     * @param array<string, array{?string, int}> $fields name => [value, column length]
     */
    private static function unstorable(array $fields): ?string
    {
        foreach ($fields as $name => [$value, $max]) {
            if ($value === null) {
                continue;
            }
            if (!mb_check_encoding($value, 'UTF-8')) {
                return sprintf('The %s is not valid UTF-8.', $name);
            }
            if (mb_strlen($value, 'UTF-8') > $max) {
                return sprintf('The %s is %d characters long; at most %d are stored.', $name, mb_strlen($value, 'UTF-8'), $max);
            }
        }

        return null;
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
            MailMessageStatus::Sending->value => $this->claimAbandoned($existing)
                // Its sender or worker died before delivering it: answered
                // Queued for good, it was never delivered.
                ? $this->takeOver($existing->with(['status' => MailMessageStatus::Pending->value]), $options, $config)
                : new MailDispatchResult(status: MailDispatchStatus::Queued, messageId: $id),
            MailMessageStatus::Queued->value => new MailDispatchResult(status: MailDispatchStatus::Queued, messageId: $id),
            // Deferred has a retry on the queue already, unless the queue is
            // this process's own: another one would run a second chain, and
            // two workers would both deliver.
            MailMessageStatus::Deferred->value => $this->queueIsProcessLocal()
                ? $this->resend($existing, $options, $config)
                : new MailDispatchResult(status: MailDispatchStatus::Queued, messageId: $id),
            // Refused for good by the server, sending it again is refused
            // again; with no reply after the message went out it may have
            // arrived, and the transport says it is not sent again.
            default => in_array($existing->getErrorCode(), [MailErrorCode::InvalidRecipient->value, MailErrorCode::Provider5xx->value, MailErrorCode::UnknownTransportError->value], true)
                ? new MailDispatchResult(status: MailDispatchStatus::Failed, messageId: $id, errorCode: $existing->getErrorCode(), errorMessage: $existing->getErrorMessage())
                : $this->resend($existing, $options, $config),
        };
    }

    /**
     * One resend of a failed or deferred row: it is taken back to `pending`
     * first, so of two send() calls retrying the key at once, one delivers
     * and the other answers Queued. Both used to deliver it.
     */
    private function resend(MailMessage $existing, MailSendOptions $options, MailerConfig $config): MailDispatchResult
    {
        if (!$this->mailRepository instanceof MailResendClaimInterface) {
            return $this->deliverOrQueue($existing->with(['errorCode' => null, 'errorMessage' => null]), $options, $config);
        }
        if (!$this->mailRepository->claimForResend($existing)) {
            return new MailDispatchResult(status: MailDispatchStatus::Queued, messageId: $existing->getId());
        }

        return $this->takeOver($existing->with(['status' => MailMessageStatus::Pending->value, 'errorCode' => null, 'errorMessage' => null]), $options, $config);
    }

    /**
     * A pending or sending row untouched for ABANDONED_AFTER_SECONDS: a send
     * or an SMTP conversation lasts seconds (MAIL_TIMEOUT), so nobody is
     * delivering it any more. Exactly one resend takes it over.
     */
    private function claimAbandoned(MailMessage $row): bool
    {
        // The database compares updated_at, in the zone it was written in.
        return $this->mailRepository instanceof MailRecoveryInterface
            && $this->mailRepository->claimAbandoned($row, new \DateTimeImmutable('-' . self::ABANDONED_AFTER_SECONDS . ' seconds'));
    }

    /**
     * A claimed row is `pending`, as a held-back mail is: delivered at once,
     * it goes through the same pending → sending step, or a held-back
     * delivery reaching its turn right after the claim would send it too.
     */
    private function takeOver(MailMessage $claimed, MailSendOptions $options, MailerConfig $config): MailDispatchResult
    {
        if (isset($this->orm) && $this->orm->getTransactionManager()->isActive()) {
            return $this->deliverOrQueue($claimed, $options, $config); // takes it after the commit
        }
        if ($this->mailRepository instanceof MailRecoveryInterface && !$this->mailRepository->takeForDelivery($claimed)) {
            return new MailDispatchResult(status: MailDispatchStatus::Queued, messageId: $claimed->getId());
        }

        return $this->deliverNow($claimed, $options, $config);
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
                // A long transaction's mails wait in `pending` with the time
                // they were stored: one older than ABANDONED_AFTER_SECONDS by
                // the time its turn comes may have been taken over by a
                // resend, which delivered it.
                if ($this->mailRepository instanceof MailRecoveryInterface && !$this->mailRepository->takeForDelivery($mailMessage)) {
                    return;
                }
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

        // Update mail message status
        $finalStatus = match ($result->status) {
            MailTransportStatus::Accepted => MailMessageStatus::Sent,
            MailTransportStatus::Deferred => MailMessageStatus::Deferred,
            default                       => MailMessageStatus::Failed,
        };

        $this->mailRepository->save(($finalStatus === MailMessageStatus::Sent ? SensitiveMailBodies::erasedIfSensitive($mailMessage) : $mailMessage)->with([
            'status' => $finalStatus->value,
            'providerMessageId' => $result->providerMessageId,
            'errorCode' => $result->errorCode,
            'errorMessage' => $result->errorMessage,
        ]));

        // The attempt after the status: written first, a database error on it
        // (the server gone away) left a delivered mail `sending`, and a resend
        // took it over and delivered it again. Its own failure is only said.
        try {
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
        } catch (\Throwable $e) {
            StaticLoggerBridge::error('mail', "The attempt of mail '{$messageId}' could not be recorded: {$e->getMessage()}", ['status' => $result->status->value]);
        }

        if ($result->status === MailTransportStatus::Accepted) {
            $warning = $result->warningForLog();
            if ($warning !== null) {
                StaticLoggerBridge::warning('mail', "Mail '{$messageId}': {$warning}");
            }

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
