<?php

declare(strict_types=1);

namespace Semitexa\Mail\Value;

final readonly class MailerConfig
{
    public function __construct(
        public string $driver = 'smtp',
        public string $host = '127.0.0.1',
        public int $port = 1025,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $encryption = null,
        public string $fromEmail = 'noreply@localhost',
        public ?string $fromName = null,
        public ?string $replyTo = null,
        public ?string $apiBaseUrl = null,
        public ?string $apiToken = null,
        public int $timeoutSeconds = 30,
        public string $queue = 'mail',
    ) {}
}
