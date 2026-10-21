<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

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
        /** Check the server's TLS certificate and host name; off only for a local test server. */
        public bool $verifyPeer = true,
    ) {}

    /**
     * `tls`, `ssl` or null for plain text. Case and spaces do not matter,
     * `starttls` is `tls`, and none/false/0/off/'' mean plain text; any other
     * value is returned as it is, and the SMTP transport refuses it.
     */
    public static function normalizeEncryption(?string $value): ?string
    {
        $value = $value === null ? null : strtolower(trim($value));

        return match ($value) {
            null, '', 'none', 'false', '0', 'off' => null,
            'starttls' => 'tls',
            default => $value,
        };
    }
}
