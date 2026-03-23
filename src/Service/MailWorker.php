<?php

declare(strict_types=1);

namespace Semitexa\Mail\Service;

use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Mail\Application\Db\MySQL\Model\MailAttemptResource;
use Semitexa\Mail\Contract\MailAttemptRepositoryInterface;
use Semitexa\Mail\Contract\MailRepositoryInterface;
use Semitexa\Mail\Contract\MailerConfigResolverInterface;
use Semitexa\Mail\Queue\Message\QueuedMailMessage;
use Semitexa\Mail\Transport\MailTransportRegistry;
use Semitexa\Mail\Value\AttachmentReference;
use Semitexa\Mail\Value\MailErrorCode;
use Semitexa\Mail\Value\MailMessageStatus;
use Semitexa\Mail\Value\MailRecipient;
use Semitexa\Mail\Value\MailTransportStatus;
use Semitexa\Mail\Value\PreparedMailMessage;
use Semitexa\Orm\Uuid\Uuid7;
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
        if ($mailMessage->status === MailMessageStatus::Sent->value) {
            $this->log("Mail message '{$message->messageId}' already sent — skipping.", 'info');
            return;
        }

        // Mark as sending
        $mailMessage->status          = MailMessageStatus::Sending->value;
        $mailMessage->last_attempt_at = new \DateTimeImmutable();
        $this->mailRepository->save($mailMessage);

        try {
            $config = $this->configResolver->resolve($mailMessage->tenant_id);
        } catch (\Throwable $e) {
            $this->failMessage($mailMessage, MailErrorCode::ConfigError->value, $e->getMessage());
            $this->log("Config error for '{$message->messageId}': {$e->getMessage()}", 'error');
            return;
        }

        // Reconstruct attachment references from stored JSON
        $attachmentRefs = [];
        if ($mailMessage->attachments_json !== null) {
            $rawAttachments = json_decode($mailMessage->attachments_json, true) ?? [];
            foreach ($rawAttachments as $raw) {
                $attachmentRefs[] = $this->deserializeAttachment($raw);
            }
        }

        // Resolve attachment contents
        try {
            $resolvedAttachments = $this->attachmentResolver->resolve($attachmentRefs);
        } catch (\Throwable $e) {
            $this->failMessage($mailMessage, MailErrorCode::AttachmentMissing->value, $e->getMessage());
            $this->log("Attachment error for '{$message->messageId}': {$e->getMessage()}", 'error');
            return;
        }

        // Reconstruct recipients
        $toRecipients  = $this->arrayToRecipients(json_decode($mailMessage->to_json, true) ?? []);
        $ccRecipients  = $mailMessage->cc_json  !== null ? $this->arrayToRecipients(json_decode($mailMessage->cc_json, true))  : [];
        $bccRecipients = $mailMessage->bcc_json !== null ? $this->arrayToRecipients(json_decode($mailMessage->bcc_json, true)) : [];

        $prepared              = new PreparedMailMessage();
        $prepared->messageId   = $message->messageId;
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
        $attemptNo               = $this->attemptRepository->countByMessageId($message->messageId) + 1;
        $attempt                 = new MailAttemptResource();
        $attempt->tenant_id      = $mailMessage->tenant_id;
        $attempt->mail_message_id = Uuid7::toBytes($message->messageId);
        $attempt->attempt_no     = $attemptNo;
        $attempt->driver         = $config->driver;
        $attempt->status         = $result->status->value;
        $attempt->started_at     = $startedAt;
        $attempt->finished_at    = $finishedAt;
        $attempt->provider_message_id  = $result->providerMessageId;
        $attempt->provider_status      = $result->providerStatus;
        $attempt->provider_response_json = $result->providerResponse !== []
            ? json_encode($result->providerResponse, JSON_THROW_ON_ERROR) : null;
        $attempt->error_code    = $result->errorCode;
        $attempt->error_message = $result->errorMessage;
        $this->attemptRepository->save($attempt);

        if ($result->status === MailTransportStatus::Accepted) {
            $mailMessage->status              = MailMessageStatus::Sent->value;
            $mailMessage->provider_message_id = $result->providerMessageId;
            $mailMessage->error_code          = null;
            $mailMessage->error_message       = null;
            $this->mailRepository->save($mailMessage);
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
            $mailMessage->status        = MailMessageStatus::Deferred->value;
            $mailMessage->error_code    = $result->errorCode;
            $mailMessage->error_message = $result->errorMessage;
            $this->mailRepository->save($mailMessage);
            $nextAttempt = $message->attempts + 1;
            $this->log("Mail '{$message->messageId}' deferred — retry {$nextAttempt}/{$message->maxRetries}.", 'warning');
            return;
        }

        // Terminal failure
        $mailMessage->provider_message_id = $result->providerMessageId;
        $this->failMessage($mailMessage, $result->errorCode, $result->errorMessage);
        $this->log("Mail '{$message->messageId}' failed permanently after {$attemptNo} attempt(s): {$result->errorMessage}", 'error');
    }

    private function failMessage(
        \Semitexa\Mail\Application\Db\MySQL\Model\MailMessageResource $mailMessage,
        ?string $errorCode,
        ?string $errorMessage,
    ): void {
        $mailMessage->status        = MailMessageStatus::Failed->value;
        $mailMessage->error_code    = $errorCode;
        $mailMessage->error_message = $errorMessage;
        $this->mailRepository->save($mailMessage);
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
