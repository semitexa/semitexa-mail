<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Mail\Domain\Contract\MailTransportInterface;
use Semitexa\Mail\Domain\Model\MailerConfig;
use Semitexa\Mail\Domain\Model\MailTransportResult;
use Semitexa\Mail\Domain\Enum\MailTransportStatus;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;

final class FakeMailTransport implements MailTransportInterface
{
    /** @var list<PreparedMailMessage> */
    private array $sent = [];

    public function key(): string
    {
        return 'fake';
    }

    public function deliver(PreparedMailMessage $message, MailerConfig $config): MailTransportResult
    {
        $this->sent[] = $message;

        return new MailTransportResult(
            status: MailTransportStatus::Accepted,
            providerMessageId: $message->messageId,
        );
    }

    /**
     * @return list<PreparedMailMessage>
     */
    public function getSentMessages(): array
    {
        return $this->sent;
    }

    public function getLastMessage(): ?PreparedMailMessage
    {
        return $this->sent !== [] ? $this->sent[array_key_last($this->sent)] : null;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function reset(): void
    {
        $this->sent = [];
    }

    public function assertSentCount(int $expected): void
    {
        $actual = count($this->sent);
        if ($actual !== $expected) {
            throw new \RuntimeException("Expected {$expected} sent messages, got {$actual}.");
        }
    }

    public function assertNothingSent(): void
    {
        $this->assertSentCount(0);
    }
}
