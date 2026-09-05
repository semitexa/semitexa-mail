<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

/**
 * One try at delivering a message: which driver, when, and what the provider
 * said back.
 *
 * The attempts are the audit trail behind a message's status — a message that
 * failed three times and a message that failed once look the same on the
 * message row, and only this says which.
 */
final readonly class MailAttempt
{
    /**
     * @param array<string, mixed> $providerResponse whatever the driver returned
     */
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $mailMessageId,
        private int $attemptNo,
        private string $driver,
        private string $status,
        private ?\DateTimeImmutable $startedAt = null,
        private ?\DateTimeImmutable $finishedAt = null,
        private ?string $providerMessageId = null,
        private ?string $providerStatus = null,
        private array $providerResponse = [],
        private ?string $errorCode = null,
        private ?string $errorMessage = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getMailMessageId(): string
    {
        return $this->mailMessageId;
    }

    public function getAttemptNo(): int
    {
        return $this->attemptNo;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function getProviderStatus(): ?string
    {
        return $this->providerStatus;
    }

    /** @return array<string, mixed> */
    public function getProviderResponse(): array
    {
        return $this->providerResponse;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /** How long the provider took, when both ends were recorded. */
    public function getDurationSeconds(): ?int
    {
        if ($this->startedAt === null || $this->finishedAt === null) {
            return null;
        }

        return $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }
}
