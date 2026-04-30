<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

use Semitexa\Mail\Domain\Enum\MailDispatchStatus;

final readonly class MailDispatchResult
{
    public function __construct(
        public MailDispatchStatus $status,
        public ?string $messageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === MailDispatchStatus::Sent
            || $this->status === MailDispatchStatus::Queued;
    }
}
