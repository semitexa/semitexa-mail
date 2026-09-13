<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\SandboxGuard;
use Semitexa\Mail\Application\Service\MailTransportRegistry;
use Semitexa\Mail\Application\Service\NullMailTransport;
use Semitexa\Mail\Application\Service\SmtpMailTransport;

/**
 * Mail does not leave a sandboxed process.
 *
 * `ai:trace replay` re-runs a recorded handler and promises nothing happens. It
 * rolls its transaction back and captures queue handoffs, but a SYNC listener
 * that sends mail went out for real.
 *
 * The decision is made here rather than by the replay runner reaching in:
 * `get()` is the one call both delivery paths use (MailService and MailWorker),
 * and this package already depends on core — so nothing has to ask whether the
 * other package is installed.
 */
final class MailTransportSandboxTest extends TestCase
{
    protected function setUp(): void
    {
        SandboxGuard::reset();
        MailTransportRegistry::reset();
    }

    protected function tearDown(): void
    {
        SandboxGuard::reset();
        MailTransportRegistry::reset();
    }

    #[Test]
    public function the_configured_driver_is_returned_when_nothing_is_sandboxed(): void
    {
        self::assertInstanceOf(SmtpMailTransport::class, MailTransportRegistry::get('smtp'));
    }

    #[Test]
    public function a_sandbox_gets_the_null_transport_whatever_the_driver_says(): void
    {
        SandboxGuard::enter('ai:trace replay');

        self::assertInstanceOf(NullMailTransport::class, MailTransportRegistry::get('smtp'));
    }

    /**
     * Withheld, not dropped: the replay envelope reports that a listener tried
     * to send. Swallowing it would make the sandbox lie in the other direction.
     */
    #[Test]
    public function the_attempt_is_recorded_with_the_driver_that_was_asked_for(): void
    {
        SandboxGuard::enter('ai:trace replay');
        MailTransportRegistry::get('smtp');

        $withheld = SandboxGuard::withheldCalls();

        self::assertCount(1, $withheld);
        self::assertSame('mail', $withheld[0]['port']);
        self::assertSame('smtp', $withheld[0]['detail']['driver']);
        self::assertSame('ai:trace replay', $withheld[0]['detail']['reason']);
    }

    /**
     * An unknown driver still throws OUTSIDE a sandbox — the guard must not
     * become a way to make a configuration mistake look fine.
     */
    #[Test]
    public function an_unknown_driver_is_still_refused_when_not_sandboxed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailTransportRegistry::get('carrier-pigeon');
    }

    /** And once the sandbox leaves, real delivery resumes. */
    #[Test]
    public function leaving_the_sandbox_restores_the_real_transport(): void
    {
        SandboxGuard::enter('replay');
        MailTransportRegistry::get('smtp');
        SandboxGuard::leave();

        self::assertInstanceOf(SmtpMailTransport::class, MailTransportRegistry::get('smtp'));
    }
}
