<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Lifecycle\SandboxGuard;
use Semitexa\Mail\Domain\Contract\MailTransportInterface;

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

        // Inside a sandbox — `ai:trace replay` re-running a recorded handler —
        // nothing may leave the process, and a SYNC listener that sends mail is
        // one of the ways it could. The decision is made HERE rather than by
        // the sandbox reaching in: this is the one call both delivery paths go
        // through (MailService and MailWorker), and mail already depends on
        // core, so nothing has to ask whether the other package is installed.
        //
        // The attempt is RECORDED before the no-op is returned. A replay whose
        // listener tried to email a customer should say so — that is a finding,
        // and swallowing it would make the sandbox lie in the other direction.
        if (SandboxGuard::isActive()) {
            SandboxGuard::withhold('mail', ['driver' => $driver, 'reason' => SandboxGuard::reason()]);

            // A FRESH NullMailTransport, never the registered 'null' entry.
            // register() is public and takes any MailTransportInterface, so an
            // application that registers one whose key() is 'null' replaces
            // that entry — and the sandbox would then hand back a transport
            // that really delivers. The guarantee here cannot depend on what
            // the registry happens to hold.
            return new NullMailTransport();
        }

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
