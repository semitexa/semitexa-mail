<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\WorkerProcess;
use Semitexa\Mail\Application\Service\MailWorkerProcesses;

/** What the worker container runs for mail (tk-worker-runs-mail-work). */
final class MailWorkerProcessesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        foreach (['EVENTS_ASYNC', 'EVENTS_TRANSPORT', 'MAIL_QUEUE'] as $key) {
            $this->env[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }

    #[Test]
    public function mail_work_reads_the_mail_queue_on_the_default_transport(): void
    {
        putenv('EVENTS_TRANSPORT=nats');

        self::assertSame(['5 mail:work nats mail'], $this->lines());
    }

    #[Test]
    public function the_queue_follows_mail_queue(): void
    {
        putenv('EVENTS_TRANSPORT=nats');
        putenv('MAIL_QUEUE=outbox');

        self::assertSame(['5 mail:work nats outbox'], $this->lines());
    }

    /** Without async events MailService sends at once: no worker could read an in-memory queue. */
    #[Test]
    public function without_async_events_nothing_is_run(): void
    {
        putenv('EVENTS_ASYNC=0');

        self::assertSame([], $this->lines());
    }

    /** @return list<string> */
    private function lines(): array
    {
        return array_map(static fn (WorkerProcess $p): string => $p->line(), (new MailWorkerProcesses())->workerProcesses());
    }
}
