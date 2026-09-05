<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Mail\Domain\Model\MailAttempt;
use Semitexa\Mail\Domain\Model\MailMessage;
use Semitexa\Mail\Domain\Contract\MailAttemptRepositoryInterface;
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

    public function __construct(
        private readonly MailRepositoryInterface $mailRepository,
        private readonly MailAttemptRepositoryInterface $attemptRepository,
        private readonly MailerConfigResolverInterface $configResolver,
        private readonly AttachmentResolver $attachmentResolver,
    ) {}

    public function setOutput(?OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function run(?string $transportName, ?string $queueName = null): void
    {
        $this->currentTransport = $transportName ?: QueueConfig::defaultTransport();
        $this->currentQueue     = $queueName     ?: 'mail';

        $transport = QueueTransportRegistry::create($this->currentTransport);

        $this->log("Mail worker started (transport={$this->currentTransport}, queue={$this->currentQueue})");

        $transport->consume($this->currentQueue, function (string $payload): void {
            $this->processPayload($payload);
        });
    }

    public function processPayload(string $payload): void
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->log("Failed to decode mail queue message: {$e->getMessage()}", 'error');
            return;
        }

        if (($data['type'] ?? '') !== QueuedMailMessage::TYPE) {
            $this->log("Unexpected message type '{$data['type']}' on mail queue — skipping.", 'warning');
            return;
        }

        try {
            $message = QueuedMailMessage::fromJson($payload);
        } catch (\Throwable $e) {
            $this->log("Failed to parse QueuedMailMessage: {$e->getMessage()}", 'error');
            return;
        }

        $this->processMessage($message);
    }

    private function processMessage(QueuedMailMessage $message): void
    {
        $mailMessage = $this->mailRepository->findById($message->messageId);

        if ($mailMessage === null) {
            $this->log("Mail message '{$message->messageId}' not found in DB — discarding.", 'warning');
            return;
        }

        // Idempotent restart safety
        if ($mailMessage->getStatus() === MailMessageStatus::Sent->value) {
            $this->log("Mail message '{$message->messageId}' already sent — skipping.", 'info');
            return;
        }

        // Mark as sending
        $mailMessage = $this->mailRepository->save($mailMessage->with([
            'status' => MailMessageStatus::Sending->value,
            'lastAttemptAt' => new \DateTimeImmutable(),
        ]));

        try {
            $config = $this->configResolver->resolve($mailMessage->getTenantId());
        } catch (\Throwable $e) {
            $this->failMessage($mailMessage, MailErrorCode::ConfigError->value, $e->getMessage());
            $this->log("Config error for '{$message->messageId}': {$e->getMessage()}", 'error');
            return;
        }

        // Reconstruct attachment references from stored JSON
        $attachmentRefs = [];
        foreach ($mailMessage->getAttachments() as $raw) {
            $attachmentRefs[] = $this->deserializeAttachment($raw);
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
        $attemptNo               = $this->attemptRepository->countByMessageId($message->messageId) + 1;
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

        if ($result->status === MailTransportStatus::Accepted) {
            $this->mailRepository->save($mailMessage->with([
                'status' => MailMessageStatus::Sent->value,
                'providerMessageId' => $result->providerMessageId,
                'errorCode' => null,
                'errorMessage' => null,
            ]));
            $this->log("Mail '{$message->messageId}' sent (attempt {$attemptNo}).", 'success');
            return;
        }

        // Determine retry eligibility
        $errorCode = $result->errorCode !== null
            ? MailErrorCode::tryFrom($result->errorCode)
            : null;

        $isRetryable = $errorCode?->isRetryable() ?? ($result->status === MailTransportStatus::Deferred);

        if ($isRetryable && $message->attempts < $message->maxRetries) {
            $this->requeueMessage($message, $config->queue, $result->errorCode, $result->errorMessage);
            $this->mailRepository->save($mailMessage->with([
                'status' => MailMessageStatus::Deferred->value,
                'errorCode' => $result->errorCode,
                'errorMessage' => $result->errorMessage,
            ]));
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
        $this->mailRepository->save($mailMessage->with([
            'status' => MailMessageStatus::Failed->value,
            'errorCode' => $errorCode,
            'errorMessage' => $errorMessage,
        ]));
    }

    private function requeueMessage(
        QueuedMailMessage $message,
        string $queue,
        ?string $errorCode,
        ?string $errorMessage,
    ): void {
        try {
            if ($message->retryDelay > 0) {
                sleep($message->retryDelay);
            }

            $retried             = clone $message;
            $retried->attempts   = $message->attempts + 1;
            $retried->queuedAt   = date(DATE_ATOM);

            $transport = QueueTransportRegistry::create(
                $this->currentTransport ?? QueueConfig::defaultTransport(),
            );
            $transport->publish($queue, $retried->toJson());
        } catch (\Throwable $e) {
            $this->log("Failed to re-enqueue mail '{$message->messageId}': {$e->getMessage()}", 'error');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function deserializeAttachment(array $data): AttachmentReference
    {
        $inlineContents = isset($data['inlineContentsBase64'])
            ? base64_decode($data['inlineContentsBase64'], true) ?: null
            : null;

        return new AttachmentReference(
            filename:       $data['filename'] ?? 'attachment',
            mimeType:       $data['mimeType'] ?? 'application/octet-stream',
            disposition:    $data['disposition'] ?? 'attachment',
            contentId:      $data['contentId'] ?? null,
            storagePath:    $data['storagePath'] ?? null,
            inlineContents: $inlineContents,
            sizeHint:       $data['sizeHint'] ?? null,
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
