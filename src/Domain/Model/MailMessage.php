<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

/**
 * One message the system has been asked to send, and how that has gone.
 *
 * Recipients, headers, tags, metadata and attachments are lists here and JSON
 * strings in the row. That encoding was being undone by hand in both MailWorker
 * and MailService — the same four decodes, written twice — because the row was
 * standing in as the model. It belongs to the mapper.
 *
 * `idempotencyKey` is the reason a retry does not send twice; `status` and the
 * error pair are the record of the attempt.
 */
final readonly class MailMessage
{
    /**
     * @param list<array{email: string, name?: string|null}> $to
     * @param list<array{email: string, name?: string|null}> $cc
     * @param list<array{email: string, name?: string|null}> $bcc
     * @param array<string, string>      $headers
     * @param list<string>               $tags
     * @param array<string, mixed>       $metadata
     * @param list<array<string, mixed>> $attachments
     */
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $status,
        private string $driver,
        private string $fromEmail,
        private string $subject,
        private array $to = [],
        private array $cc = [],
        private array $bcc = [],
        private ?string $templateHandle = null,
        private ?string $fromName = null,
        private ?string $replyTo = null,
        private ?string $htmlBody = null,
        private ?string $textBody = null,
        private array $headers = [],
        private array $tags = [],
        private array $metadata = [],
        private array $attachments = [],
        private ?string $idempotencyKey = null,
        private ?string $providerMessageId = null,
        private ?string $errorCode = null,
        private ?string $errorMessage = null,
        private ?\DateTimeImmutable $lastAttemptAt = null,
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    /** @return list<array{email: string, name?: string|null}> */
    public function getTo(): array
    {
        return $this->to;
    }

    /** @return list<array{email: string, name?: string|null}> */
    public function getCc(): array
    {
        return $this->cc;
    }

    /** @return list<array{email: string, name?: string|null}> */
    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function getTemplateHandle(): ?string
    {
        return $this->templateHandle;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function getHtmlBody(): ?string
    {
        return $this->htmlBody;
    }

    public function getTextBody(): ?string
    {
        return $this->textBody;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** @return list<array<string, mixed>> */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * A copy with some fields replaced. Only the fields callers actually change
     * after a send attempt are settable; the message itself does not rewrite.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $str = fn (string $k, ?string $current): ?string => array_key_exists($k, $changes)
            ? (is_string($changes[$k]) ? $changes[$k] : null)
            : $current;

        return new self(
            id: is_string($changes['id'] ?? null) ? $changes['id'] : $this->id,
            tenantId: $this->tenantId,
            status: is_string($changes['status'] ?? null) ? $changes['status'] : $this->status,
            driver: is_string($changes['driver'] ?? null) ? $changes['driver'] : $this->driver,
            fromEmail: $this->fromEmail,
            subject: $this->subject,
            to: $this->to,
            cc: $this->cc,
            bcc: $this->bcc,
            templateHandle: $this->templateHandle,
            fromName: $this->fromName,
            replyTo: $this->replyTo,
            htmlBody: $this->htmlBody,
            textBody: $this->textBody,
            headers: $this->headers,
            tags: $this->tags,
            metadata: $this->metadata,
            attachments: $this->attachments,
            idempotencyKey: $this->idempotencyKey,
            providerMessageId: $str('providerMessageId', $this->providerMessageId),
            errorCode: $str('errorCode', $this->errorCode),
            errorMessage: $str('errorMessage', $this->errorMessage),
            lastAttemptAt: array_key_exists('lastAttemptAt', $changes)
                ? ($changes['lastAttemptAt'] instanceof \DateTimeImmutable ? $changes['lastAttemptAt'] : null)
                : $this->lastAttemptAt,
            createdAt: array_key_exists('createdAt', $changes)
                ? ($changes['createdAt'] instanceof \DateTimeImmutable ? $changes['createdAt'] : null)
                : $this->createdAt,
            updatedAt: array_key_exists('updatedAt', $changes)
                ? ($changes['updatedAt'] instanceof \DateTimeImmutable ? $changes['updatedAt'] : null)
                : $this->updatedAt,
        );
    }
}
