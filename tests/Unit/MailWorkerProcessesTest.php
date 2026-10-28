<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\WorkerProcess;
use Semitexa\Mail\Application\Service\EnvMailerConfigResolver;
use Semitexa\Mail\Application\Service\MailWorkerProcesses;
use Semitexa\Mail\Domain\Contract\MailerConfigResolverInterface;
use Semitexa\Mail\Domain\Model\MailerConfig;

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

    /**
     * REGRESSION (tk-mail-worker-per-tenant-queues). MailService queues a mail
     * on the queue the mailer config resolver names; the worker read
     * MAIL_QUEUE itself, so a resolver that names another queue had its mail
     * queued where no mail:work read it. The worker now asks the resolver.
     */
    #[Test]
    public function the_queue_is_the_one_the_mailer_config_resolver_names(): void
    {
        putenv('EVENTS_TRANSPORT=nats');
        putenv('MAIL_QUEUE=outbox');

        self::assertSame(['5 mail:work nats transactional'], $this->lines(new MailWorkerFixtureResolver('transactional')));
    }

    /** So worker:processes can refuse a queue:work on the mail queue (tk-nats-consumer-name-collision). */
    #[Test]
    public function mail_work_says_which_queue_it_reads(): void
    {
        putenv('EVENTS_TRANSPORT=nats');

        self::assertSame([['nats', 'mail']], array_map(static fn (WorkerProcess $p): ?array => $p->reads, $this->processes()));
    }

    /** @return list<string> */
    private function lines(MailerConfigResolverInterface $resolver = new EnvMailerConfigResolver()): array
    {
        return array_map(static fn (WorkerProcess $p): string => $p->line(), $this->processes($resolver));
    }

    /** @return list<WorkerProcess> */
    private function processes(MailerConfigResolverInterface $resolver = new EnvMailerConfigResolver()): array
    {
        $source = new MailWorkerProcesses();
        // What the container injects.
        (new \ReflectionProperty($source, 'mailerConfigResolver'))->setValue($source, $resolver);

        return $source->workerProcesses();
    }
}

final class MailWorkerFixtureResolver implements MailerConfigResolverInterface
{
    public function __construct(private readonly string $queue)
    {
    }

    public function resolve(?string $tenantId = null, ?string $mailerKey = null): MailerConfig
    {
        return new MailerConfig(queue: $this->queue);
    }
}
