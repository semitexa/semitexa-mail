<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Environment;
use Semitexa\Core\Queue\DelayedQueueTransportInterface;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\QueueStatsRecorder;
use Semitexa\Core\Queue\QueueStopSignals;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Mail\Domain\Model\MailAttempt;
use Semitexa\Mail\Domain\Model\MailMessage;
use Semitexa\Mail\Domain\Contract\MailAttemptRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailRetentionInterface;
use Semitexa\Mail\Domain\Contract\MailRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailerConfigResolverInterface;
use Semitexa\Mail\Domain\Model\QueuedMailMessage;
use Semitexa\Mail\Application\Service\MailTransportRegistry;
use Semitexa\Mail\Domain\Model\AttachmentReference;
use Semitexa\Mail\Domain\Enum\MailErrorCode;
use Semitexa\Mail\Domain\Enum\MailMessageStatus;
use Semitexa\Mail\Domain\Model\MailRecipient;
use Semitexa\Mail\Domain\Enum\MailTransportStatus;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;
use Symfony\Component\Console\Output\OutputInterface;

final class MailWorker
{
    private ?string $currentTransport = null;
    private ?string $currentQueue     = null;
    private ?OutputInterface $output  = null;
    private int $nextSweepAt = 0;
    /** What the message being processed came to, for queue:stats: null counts nothing. */
    private ?string $outcome = null;
    /** When the attempt's own work ended: before a retry's delay, which is not run time. */
    private ?float $attemptEndedAt = null;
    private ?QueueStatsRecorder $stats = null;
    private bool $statsResolved = false;

    public function __construct(
        private readonly MailRepositoryInterface $mailRepository,
        private readonly MailAttemptRepositoryInterface $attemptRepository,
        private readonly MailerConfigResolverInterface $configResolver,
        private readonly AttachmentResolver $attachmentResolver,
        private readonly ?MailRetentionInterface $retention = null,
    ) {}

    /** For tests: record into this, for $queue, instead of the configured store. */
    public function withStats(?QueueStatsRecorder $stats, string $queue): self
    {
        $this->stats = $stats;
        $this->statsResolved = true;
        $this->currentQueue = $queue;

        return $this;
    }

    public function setOutput(?OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function run(?string $transportName, ?string $queueName = null): void
    {
        $this->currentTransport = $transportName ?: QueueConfig::defaultTransport();
        // MAIL_QUEUE, as send() publishes to; it read the literal 'mail'.
        if ($queueName === null || $queueName === '') {
            try {
                $queueName = $this->configResolver->resolve()->queue;
            } catch (\Throwable $e) {
                $this->log("No mailer config without a tenant ({$e->getMessage()}); reading the 'mail' queue.", 'warning');
                $queueName = 'mail';
            }
        }
        $this->currentQueue     = $queueName;

        $transport = QueueTransportRegistry::create($this->currentTransport);
        QueueStopSignals::install($transport, fn (string $message) => $this->log($message));

        $this->log("Mail worker started (transport={$this->currentTransport}, queue={$this->currentQueue})");

        $transport->consume($this->currentQueue, function (string $payload): void {
            $this->processPayload($payload);
        });
    }

    public function processPayload(string $payload): void
    {
        try {
            $this->processPayloadNow($payload);
        } finally {
            // After the message, never before it: a reset or one-time code
            // must not wait behind the cleanup.
            $this->sweepOldMail();
        }
    }

    private function processPayloadNow(string $payload): void
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->log("Failed to decode mail queue message: {$e->getMessage()}", 'error');
            return;
        }

        if (($data['type'] ?? '') !== QueuedMailMessage::TYPE) {
            $type = is_array($data) && is_scalar($data['type'] ?? null) ? (string) $data['type'] : '(none)';
            $this->log("Unexpected message type '{$type}' on mail queue — skipping.", 'warning');
            return;
        }

        try {
            $message = QueuedMailMessage::fromJson($payload);
        } catch (\Throwable $e) {
            $this->log("Failed to parse QueuedMailMessage: {$e->getMessage()}", 'error');
            return;
        }

        $this->outcome = null;
        $this->attemptEndedAt = null;
        $startedAt = microtime(true);
        try {
            $this->processMessage($message);
        } finally {
            $this->recordStats($message, $startedAt);
        }
    }

    /**
     * Sent, retried or failed for good, into the same per-queue counters
     * queue:work writes, so queue:stats and the Queues app count mail too. A
     * skipped message (already sent, its row gone, an outcome another worker
     * wrote first) counts nothing. No wait: queuedAt has whole seconds, which
     * would put every wait in the 1 s bucket.
     */
    private function recordStats(QueuedMailMessage $message, float $startedAt): void
    {
        if ($this->outcome === null || $this->currentQueue === null) {
            return;
        }
        if (!$this->statsResolved) {
            $this->statsResolved = true;
            $this->stats = QueueStatsRecorder::fromEnvironment();
        }
        $this->stats?->record(
            $this->currentQueue,
            $this->outcome === 'dead_lettered' ? 'dead_lettered' : 'processed',
            $this->outcome === 'retried',
            null,
            (int) round((($this->attemptEndedAt ?? microtime(true)) - $startedAt) * 1000),
        );
    }

    /**
     * With MAIL_RETENTION_DAYS set, sent and failed mail older than that goes,
     * with its attempts, at most once an hour: nothing deleted a row, inline
     * attachments included. 0 (the default) keeps everything.
     */
    private function sweepOldMail(): void
    {
        $days = (int) Environment::getEnvValue('MAIL_RETENTION_DAYS', '0');
        if ($this->retention === null || $days <= 0 || time() < $this->nextSweepAt) {
            return;
        }
        $this->nextSweepAt = time() + 3600;

        try {
            $cutoff = (new \DateTimeImmutable())->modify("-{$days} days");
            $deleted = 0;
            // At most 20 batches and about two seconds: the rest waits an hour.
            $until = microtime(true) + 2.0;
            for ($batch = 0; $batch < 20 && microtime(true) < $until; $batch++) {
                $n = $this->retention->deleteFinishedBefore($cutoff, 500);
                $deleted += $n;
                if ($n < 500) {
                    break;
                }
            }
            if ($deleted > 0) {
                $this->log("Deleted {$deleted} sent or failed mail(s) older than {$days} days.");
            }
        } catch (\Throwable $e) {
            $this->log("Mail retention sweep failed: {$e->getMessage()}", 'warning');
        }
    }

    private function processMessage(QueuedMailMessage $message): void
    {
        $mailMessage = $this->mailRepository->findById($message->messageId);

        if ($mailMessage === null) {
            // MailService publishes after the commit of a transaction on its
            // own connection, but a sender in a transaction it cannot see (raw
            // PDO, another connection) still publishes first: a worker quicker
            // than that commit found no row and dropped the mail. A fresh
            // message is looked at again a few seconds later; one whose row
            // never appears (the sender rolled back) is dropped after 30 s.
            $queuedAt = strtotime($message->queuedAt) ?: 0;
            if (time() - $queuedAt < 30 && $this->republishLater($message, 3)) {
                $this->log("Mail message '{$message->messageId}' not in the DB yet — looking again shortly.", 'info');
                return;
            }
            $this->log("Mail message '{$message->messageId}' not found in DB — discarding.", 'warning');
            return;
        }

        // Idempotent restart safety
        if ($mailMessage->getStatus() === MailMessageStatus::Sent->value) {
            $this->log("Mail message '{$message->messageId}' already sent — skipping.", 'info');
            return;
        }

        // Mark as sending, unless another worker on a redelivered copy has
        // sent it since the read above: a plain save set it back to sending.
        $mailMessage = $mailMessage->with([
            'status' => MailMessageStatus::Sending->value,
            'lastAttemptAt' => new \DateTimeImmutable(),
        ]);
        if (!$this->mailRepository->saveOutcomeUnlessSent($mailMessage)) {
            $this->log("Mail message '{$message->messageId}' already sent — skipping.", 'info');
            return;
        }

        try {
            // With its mailerKey: the worker resolved every queued mail to the
            // tenant's default mailer, whichever one send() had chosen.
            $config = $this->configResolver->resolve($mailMessage->getTenantId(), $mailMessage->getMailerKey());
        } catch (\Throwable $e) {
            $this->failMessage($mailMessage, MailErrorCode::ConfigError->value, $e->getMessage());
            $this->log("Config error for '{$message->messageId}': {$e->getMessage()}", 'error');
            return;
        }

        // Reconstruct attachment references from stored JSON
        $attachmentRefs = [];
        foreach ($mailMessage->getAttachments() as $raw) {
            $attachmentRefs[] = AttachmentReference::fromStored($raw);
        }

        // Resolve attachment contents
        try {
            $resolvedAttachments = $this->attachmentResolver->resolve($attachmentRefs);
        } catch (\Throwable $e) {
            $this->failMessage($mailMessage, MailErrorCode::AttachmentMissing->value, $e->getMessage());
            $this->log("Attachment error for '{$message->messageId}': {$e->getMessage()}", 'error');
            return;
        }

        $prepared              = new PreparedMailMessage();
        $prepared->messageId   = $message->messageId;
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

        // An unknown MAIL_DRIVER threw back to the queue, which redelivered
        // until it gave up, and the row stayed `sending` for good.
        try {
            $transport = MailTransportRegistry::get($config->driver);
        } catch (\InvalidArgumentException $e) {
            $this->failMessage($mailMessage, MailErrorCode::ConfigError->value, $e->getMessage());
            $this->log("Config error for '{$message->messageId}': {$e->getMessage()}", 'error');
            return;
        }
        $startedAt  = new \DateTimeImmutable();
        $result     = $transport->deliver($prepared, $config);
        $finishedAt = new \DateTimeImmutable();
        $this->attemptEndedAt = microtime(true);

        // The attempt is recorded after the mail's status: written first, a
        // database error on it (the server gone away) left a delivered mail
        // `sending`, the queue redelivered it, and it was delivered twice.
        // Its own failure is only said.
        $recordAttempt = function () use ($message, $mailMessage, $config, $result, $startedAt, $finishedAt): int {
            try {
                $attemptNo = $this->attemptRepository->countByMessageId($message->messageId) + 1;
                $this->attemptRepository->save(new MailAttempt(
                    id: '',
                    tenantId: $mailMessage->getTenantId(),
                    mailMessageId: $message->messageId,
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

                return $attemptNo;
            } catch (\Throwable $e) {
                $this->log("The attempt of mail '{$message->messageId}' could not be recorded: {$e->getMessage()}", 'error');

                return 0;
            }
        };

        if ($result->status === MailTransportStatus::Accepted) {
            $this->mailRepository->save($mailMessage->with([
                'status' => MailMessageStatus::Sent->value,
                'providerMessageId' => $result->providerMessageId,
                'errorCode' => null,
                'errorMessage' => null,
            ]));
            $attemptNo = $recordAttempt();
            $this->outcome = 'processed';
            $this->log("Mail '{$message->messageId}' sent (attempt {$attemptNo}).", 'success');
            return;
        }

        $attemptNo = $recordAttempt();

        // Determine retry eligibility
        $errorCode = $result->errorCode !== null
            ? MailErrorCode::tryFrom($result->errorCode)
            : null;

        $isRetryable = $errorCode?->isRetryable() ?? ($result->status === MailTransportStatus::Deferred);

        if ($isRetryable && $message->attempts < $message->maxRetries) {
            // Deferred before the retry is published, and never over a `sent`
            // another worker wrote meanwhile (a redelivered message processed
            // twice at once); if it cannot be published the row says so
            // instead of waiting for a retry that does not exist.
            $mailMessage = $mailMessage->with([
                'status' => MailMessageStatus::Deferred->value,
                'errorCode' => $result->errorCode,
                'errorMessage' => $result->errorMessage,
            ]);
            if (!$this->mailRepository->saveOutcomeUnlessSent($mailMessage)) {
                $this->log("Mail '{$message->messageId}' was sent by another worker meanwhile.", 'info');
                return;
            }
            $requeueError = $this->requeueMessage($message, $config->queue);
            if ($requeueError !== null) {
                if ($this->mailRepository->saveOutcomeUnlessSent($mailMessage->with([
                    'status' => MailMessageStatus::EnqueueFailed->value,
                    'errorCode' => MailErrorCode::QueueUnavailable->value,
                    'errorMessage' => "The retry could not be queued: {$requeueError}",
                ]))) {
                    $this->outcome = 'dead_lettered';
                }
                return;
            }
            $this->outcome = 'retried';
            $nextAttempt = $message->attempts + 1;
            $this->log("Mail '{$message->messageId}' deferred — retry {$nextAttempt}/{$message->maxRetries}.", 'warning');
            return;
        }

        // Terminal failure
        $mailMessage = $mailMessage->with(['providerMessageId' => $result->providerMessageId]);
        $this->failMessage($mailMessage, $result->errorCode, $result->errorMessage);
        $this->log("Mail '{$message->messageId}' failed permanently after {$attemptNo} attempt(s): {$result->errorMessage}", 'error');
    }

    private function failMessage(
        MailMessage $mailMessage,
        ?string $errorCode,
        ?string $errorMessage,
    ): void {
        // Never over a `sent` another worker wrote meanwhile; and then this
        // worker failed nothing, so it counts nothing.
        if ($this->mailRepository->saveOutcomeUnlessSent($mailMessage->with([
            'status' => MailMessageStatus::Failed->value,
            'errorCode' => $errorCode,
            'errorMessage' => $errorMessage,
        ]))) {
            $this->outcome = 'dead_lettered';
        }
    }

    /** The same queue message again, its attempts and queuedAt unchanged, $delay seconds later. */
    private function republishLater(QueuedMailMessage $message, int $delay): bool
    {
        try {
            $transport = QueueTransportRegistry::create($this->currentTransport ?? QueueConfig::defaultTransport());
            // Only where the transport holds it back: a sleep here would stall
            // every mail behind it, the reason retries no longer sleep.
            if (!$transport instanceof DelayedQueueTransportInterface) {
                return false;
            }
            $transport->publishDelayed($this->currentQueue ?? 'mail', $message->toJson(), $delay);

            return true;
        } catch (\Throwable $e) {
            $this->log("Could not look at mail '{$message->messageId}' again: {$e->getMessage()}", 'warning');

            return false;
        }
    }

    /**
     * Publish the next attempt. A transport that can hold a message back gets
     * it delayed; the worker used to sleep the delay inside the consumer, and
     * one failing mail held up every mail behind it.
     *
     * @return string|null why it could not be published
     */
    private function requeueMessage(QueuedMailMessage $message, string $queue): ?string
    {
        try {
            $retried             = clone $message;
            $retried->attempts   = $message->attempts + 1;
            $retried->queuedAt   = date(DATE_ATOM);

            $transport = QueueTransportRegistry::create(
                $this->currentTransport ?? QueueConfig::defaultTransport(),
            );
            if ($message->retryDelay > 0 && $transport instanceof DelayedQueueTransportInterface) {
                $transport->publishDelayed($queue, $retried->toJson(), $message->retryDelay);

                return null;
            }
            if ($message->retryDelay > 0) {
                sleep($message->retryDelay);
            }
            $transport->publish($queue, $retried->toJson());

            return null;
        } catch (\Throwable $e) {
            $this->log("Failed to re-enqueue mail '{$message->messageId}': {$e->getMessage()}", 'error');

            return $e->getMessage();
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function log(string $message, string $level = 'info'): void
    {
        if ($this->output !== null) {
            $tag = match ($level) {
                'error'   => 'error',
                'warning' => 'comment',
                'success' => 'info',
                default   => 'info',
            };
            $this->output->writeln("<{$tag}>{$message}</{$tag}>");
        } else {
            echo "{$message}\n";
        }
    }
}
