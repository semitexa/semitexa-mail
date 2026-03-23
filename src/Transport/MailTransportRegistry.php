<?php

declare(strict_types=1);

namespace Semitexa\Mail\Transport;

use Semitexa\Mail\Contract\MailTransportInterface;

final class MailTransportRegistry
{
    /** @var array<string, MailTransportInterface> */
    private static array $transports = [];

    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::register(new SmtpMailTransport());
        self::register(new NullMailTransport());

        self::$booted = true;
    }

    public static function register(MailTransportInterface $transport): void
    {
        self::$transports[$transport->key()] = $transport;
    }

    public static function get(string $driver): MailTransportInterface
    {
        self::boot();

        if (!isset(self::$transports[$driver])) {
            throw new \InvalidArgumentException("Unknown mail transport driver: '{$driver}'. Registered: " . implode(', ', array_keys(self::$transports)));
        }

        return self::$transports[$driver];
    }

    public static function reset(): void
    {
        self::$transports = [];
        self::$booted = false;
    }
}
