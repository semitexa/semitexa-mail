<?php

declare(strict_types=1);

namespace Semitexa\Mail\Value;

final readonly class MailTransportResult
{
    public function __construct(
        public MailTransportStatus $status,
        public ?string $providerMessageId = null,
        public ?string $providerStatus = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        /** @var array<string, mixed> */
        public array $providerResponse = [],
    ) {}
}
