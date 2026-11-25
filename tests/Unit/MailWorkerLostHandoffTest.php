<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Mail\Application\Service\AttachmentResolver;
use Semitexa\Mail\Application\Service\MailWorker;
use Semitexa\Mail\Domain\Contract\MailAttemptRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailerConfigResolverInterface;
use Semitexa\Mail\Domain\Contract\MailHandoffRecoveryInterface;
use Semitexa\Mail\Domain\Contract\MailRecoveryClaimInterface;
use Semitexa\Mail\Domain\Contract\MailRepositoryInterface;
use Semitexa\Mail\Domain\Model\MailerConfig;
use Semitexa\Mail\Domain\Model\MailMessage;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * tk-mail-lost-handoff-no-recovery: the repository could list and claim the
 * mails whose queue publish was lost, and nothing called it. mail:work now
 * sweeps them: at start and after a message, at most every five minutes.
 */
final class MailWorkerLostHandoffTest extends TestCase
{
    private const TRANSPORT = 'mail-handoff-test';

    /** Read by the worker's own payload check and skipped: only the sweeps after it run. */
    private const ANY_PAYLOAD = '{"type":"other"}';

    /** @var array<string, string|false> */
    private array $env = [];

    /** @var array<string, mixed> QueueTransportRegistry's statics as the test found them */
    private array $registry = [];

    private MailWorkerHandoffQueueSpy $queue;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        foreach (['EVENTS_ASYNC', 'EVENTS_TRANSPORT', 'MAIL_LOST_HANDOFF_MINUTES', 'MAIL_RETENTION_DAYS'] as $key) {
            $this->env[$key] = getenv($key);
        }
        foreach ((new \ReflectionClass(QueueTransportRegistry::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $this->registry[$property->getName()] = $property->getValue();
        }
        $this->queue = $queue = new MailWorkerHandoffQueueSpy();
        QueueTransportRegistry::reset();
        QueueTransportRegistry::register(self::TRANSPORT, new class($queue) implements QueueTransportFactoryInterface {
            public function __construct(private readonly MailWorkerHandoffQueueSpy $queue)
            {
            }

            public function create(): QueueTransportInterface
            {
                return $this->queue;
            }
        });
        putenv('EVENTS_TRANSPORT=' . self::TRANSPORT);
        putenv('MAIL_LOST_HANDOFF_MINUTES');
        putenv('MAIL_RETENTION_DAYS');
        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        // Put back, not cleared: what was set before this class is there for the tests after it.
        foreach ($this->env as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        foreach ($this->registry as $name => $value) {
            (new \ReflectionProperty(QueueTransportRegistry::class, $name))->setValue(null, $value);
        }
    }

    #[Test]
    public function a_lost_mail_is_published_once_more_as_its_first_send_published_it(): void
    {
        $recovery = new MailWorkerHandoffRecovery([$this->mail('m-1', tenantId: 'acme', mailerKey: 'billing')]);
        $before = time();

        $this->worker($recovery)->processPayload(self::ANY_PAYLOAD);

        self::assertCount(1, $this->queue->published);
        [$queue, $payload] = $this->queue->published[0];
        self::assertSame('outbox-acme-billing', $queue, "the queue of the mail's own mailer, as send() resolved it");
        $message = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($message);
        self::assertGreaterThanOrEqual($before, strtotime((string) $message['queuedAt']));
        unset($message['queuedAt']);
        self::assertSame(
            ['type' => 'mail', 'messageId' => 'm-1', 'attempts' => 0, 'maxRetries' => 3, 'retryDelay' => 60, 'takeoverOnly' => false, 'recoveryOnly' => true],
            $message,
            'what the first send published, marked as the recovery copy',
        );
        self::assertSame([100], $recovery->limits);
        self::assertEqualsWithDelta($before - 900, $recovery->cutoffs[0]->getTimestamp(), 5, 'untouched for 15 minutes');
        self::assertSame([['m-1', $recovery->cutoffs[0]]], $recovery->claims, 'claimed with the cutoff it was listed by');
        self::assertSame(
            "Unexpected message type 'other' on mail queue — skipping.\n"
            . "Published 1 unattempted mail(s) again: untouched for 15 minutes.\n",
            $this->output->fetch(),
        );
    }

    #[Test]
    public function a_mail_another_worker_claimed_first_is_not_published(): void
    {
        $recovery = new MailWorkerHandoffRecovery([$this->mail('m-1'), $this->mail('m-2')]);
        $recovery->taken = ['m-1' => true];

        $this->worker($recovery)->processPayload(self::ANY_PAYLOAD);

        self::assertSame(['m-2'], $this->queue->messageIds());
        self::assertSame(['m-1', 'm-2'], array_column($recovery->claims, 0));
    }

    #[Test]
    public function the_sweep_runs_at_most_every_five_minutes(): void
    {
        self::assertSame(300, MailWorker::LOST_HANDOFF_SWEEP_SECONDS);
        $recovery = new MailWorkerHandoffRecovery([$this->mail('m-1')]);
        $worker = $this->worker($recovery);
        $next = new \ReflectionProperty(MailWorker::class, 'nextHandoffSweepAt');

        $before = time();
        $worker->processPayload(self::ANY_PAYLOAD);
        $after = time();
        // A mail lost in between waits: the second and third message sweep nothing.
        $recovery->lost = [$this->mail('m-2')];
        $worker->processPayload(self::ANY_PAYLOAD);
        $worker->processPayload(self::ANY_PAYLOAD);

        self::assertCount(1, $recovery->cutoffs, 'once, not on every message');
        self::assertSame(['m-1'], $this->queue->messageIds());
        self::assertGreaterThanOrEqual($before + 300, $next->getValue($worker));
        self::assertLessThanOrEqual($after + 300, $next->getValue($worker));

        // One second short of five minutes later, and then at five minutes.
        $next->setValue($worker, time() + 1);
        $worker->processPayload(self::ANY_PAYLOAD);
        self::assertCount(1, $recovery->cutoffs);
        $next->setValue($worker, time());
        $worker->processPayload(self::ANY_PAYLOAD);
        self::assertCount(2, $recovery->cutoffs);
        self::assertSame(['m-1', 'm-2'], $this->queue->messageIds());
    }

    #[Test]
    public function the_worker_sweeps_when_it_starts_before_it_reads_the_queue(): void
    {
        $recovery = new MailWorkerHandoffRecovery([$this->mail('m-1')]);

        $this->worker($recovery)->run(self::TRANSPORT, 'mail');

        self::assertSame(['publish outbox-system-default m-1', 'consume mail'], $this->queue->calls);
    }

    #[Test]
    public function a_recovery_that_throws_is_said_and_the_worker_goes_on(): void
    {
        $recovery = new MailWorkerHandoffRecovery([]);
        $recovery->listFails = new \RuntimeException('MySQL server has gone away');
        $worker = $this->worker($recovery);

        $worker->processPayload(self::ANY_PAYLOAD);

        self::assertSame(
            "Unexpected message type 'other' on mail queue — skipping.\n"
            . "Mail lost-handoff sweep failed: MySQL server has gone away\n",
            $this->output->fetch(),
        );
        // The consumer is still there for the next message, and sweeps again in its turn.
        $recovery->listFails = null;
        $recovery->lost = [$this->mail('m-1')];
        (new \ReflectionProperty(MailWorker::class, 'nextHandoffSweepAt'))->setValue($worker, time());
        $worker->processPayload(self::ANY_PAYLOAD);
        self::assertSame(['m-1'], $this->queue->messageIds());
    }

    #[Test]
    public function one_mail_that_cannot_be_published_does_not_hold_back_the_next(): void
    {
        $recovery = new MailWorkerHandoffRecovery([$this->mail('m-1'), $this->mail('m-2', mailerKey: 'unknown'), $this->mail('m-3')]);
        $this->queue->refuse = ['m-1' => 'broker away'];

        $this->worker($recovery)->processPayload(self::ANY_PAYLOAD);

        self::assertSame(['m-3'], $this->queue->messageIds());
        self::assertSame(['m-1', 'm-2', 'm-3'], array_column($recovery->claims, 0), 'a mail with no mailer to resolve is claimed too: unclaimed, it stays at the head of the list for every sweep');
        self::assertSame(
            "Unexpected message type 'other' on mail queue — skipping.\n"
            . "Could not publish unattempted mail 'm-1' again: broker away\n"
            . "Could not publish unattempted mail 'm-2' again: no mailer 'unknown'\n"
            . "Published 1 unattempted mail(s) again: untouched for 15 minutes.\n",
            $this->output->fetch(),
        );
    }

    #[Test]
    public function the_window_is_mail_lost_handoff_minutes_and_zero_turns_the_sweep_off(): void
    {
        $recovery = new MailWorkerHandoffRecovery([$this->mail('m-1')]);

        putenv('MAIL_LOST_HANDOFF_MINUTES=0');
        $this->worker($recovery)->processPayload(self::ANY_PAYLOAD);
        self::assertSame([], $recovery->cutoffs);
        self::assertSame([], $this->queue->published);

        putenv('MAIL_LOST_HANDOFF_MINUTES=45');
        $before = time();
        $this->worker($recovery)->processPayload(self::ANY_PAYLOAD);
        self::assertEqualsWithDelta($before - 2700, $recovery->cutoffs[0]->getTimestamp(), 5);
        self::assertSame(['m-1'], $this->queue->messageIds());
    }

    #[Test]
    public function without_async_events_nothing_is_claimed_or_published(): void
    {
        // send() delivers in its own process then, and no worker reads the in-memory queue.
        putenv('EVENTS_TRANSPORT');
        putenv('EVENTS_ASYNC=0');
        $recovery = new MailWorkerHandoffRecovery([$this->mail('m-1')]);

        $this->worker($recovery)->processPayload(self::ANY_PAYLOAD);

        self::assertSame([], $recovery->cutoffs);
        self::assertSame([], $recovery->claims);
        self::assertSame([], $this->queue->published);
    }

    #[Test]
    public function a_worker_given_no_recovery_sweeps_nothing(): void
    {
        $this->worker(null)->processPayload(self::ANY_PAYLOAD);

        self::assertSame([], $this->queue->published);
        self::assertSame("Unexpected message type 'other' on mail queue — skipping.\n", $this->output->fetch());
    }

    /**
     * A project's own repository without the recovery claim: no worker could
     * take a copy by one write, so none is published and one that arrives is
     * skipped.
     */
    #[Test]
    public function a_repository_without_the_recovery_claim_gets_no_sweep_and_delivers_no_recovery_copy(): void
    {
        $recovery = new MailWorkerHandoffRecovery([$this->mail('m-1')]);
        $repository = $this->createMock(MailRepositoryInterface::class);
        $repository->method('findById')->willReturn($this->mail('m-1'));
        $repository->expects(self::never())->method('save');

        $this->worker($recovery, $repository)->processPayload('{"type":"mail","messageId":"m-1","recoveryOnly":true}');

        self::assertSame([], $recovery->cutoffs);
        self::assertSame([], $this->queue->published);
        self::assertSame("Mail message 'm-1' is not waiting untried (queued when read) — skipping the recovery copy.\n", $this->output->fetch());
    }

    private function worker(?MailHandoffRecoveryInterface $recovery, ?MailRepositoryInterface $repository = null): MailWorker
    {
        $config = new class implements MailerConfigResolverInterface {
            public function resolve(?string $tenantId = null, ?string $mailerKey = null): MailerConfig
            {
                if ($mailerKey === 'unknown') {
                    throw new \RuntimeException("no mailer 'unknown'");
                }

                return new MailerConfig(driver: 'smtp', queue: 'outbox-' . ($tenantId ?? 'system') . '-' . ($mailerKey ?? 'default'));
            }
        };
        $worker = new MailWorker(
            $repository ?? self::createStubForIntersectionOfInterfaces([MailRepositoryInterface::class, MailRecoveryClaimInterface::class]),
            self::createStub(MailAttemptRepositoryInterface::class),
            $config,
            (new \ReflectionClass(AttachmentResolver::class))->newInstanceWithoutConstructor(),
            null,
            $recovery,
        );
        $this->output = new BufferedOutput();
        $worker->setOutput($this->output);

        return $worker;
    }

    private function mail(string $id, ?string $tenantId = null, ?string $mailerKey = null): MailMessage
    {
        return new MailMessage(
            id: $id, tenantId: $tenantId, status: 'queued', driver: 'smtp', fromEmail: 'noreply@example.test', subject: 'Verify',
            to: [['email' => 'ada@example.test', 'name' => 'Ada']], textBody: 'Hello', mailerKey: $mailerKey,
        );
    }
}

final class MailWorkerHandoffRecovery implements MailHandoffRecoveryInterface
{
    /** @var list<\DateTimeImmutable> the cutoff of every lostHandoffs() */
    public array $cutoffs = [];

    /** @var list<int> */
    public array $limits = [];

    /** @var list<array{string, \DateTimeImmutable}> every claimLostHandoff(): the mail and its cutoff */
    public array $claims = [];

    /** @var array<string, true> mails another worker claimed first */
    public array $taken = [];

    public ?\Throwable $listFails = null;

    /** @param list<MailMessage> $lost */
    public function __construct(public array $lost)
    {
    }

    public function lostHandoffs(\DateTimeImmutable $untouchedSince, int $limit): array
    {
        $this->cutoffs[] = $untouchedSince;
        $this->limits[] = $limit;
        if ($this->listFails !== null) {
            throw $this->listFails;
        }

        return $this->lost;
    }

    public function claimLostHandoff(MailMessage $message, \DateTimeImmutable $untouchedSince): bool
    {
        $this->claims[] = [$message->getId(), $untouchedSince];
        if (isset($this->taken[$message->getId()])) {
            return false;
        }
        // As the repository: claimed, it is touched, and no second claim takes it.
        $this->taken[$message->getId()] = true;

        return true;
    }
}

final class MailWorkerHandoffQueueSpy implements QueueTransportInterface
{
    /** @var list<array{string, string}> queue and payload */
    public array $published = [];

    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, string> message id => why its publish fails */
    public array $refuse = [];

    public function publish(string $queueName, string $payload): void
    {
        $id = (string) (json_decode($payload, true)['messageId'] ?? '');
        if (isset($this->refuse[$id])) {
            throw new \RuntimeException($this->refuse[$id]);
        }
        $this->published[] = [$queueName, $payload];
        $this->calls[] = "publish {$queueName} {$id}";
    }

    public function consume(string $queueName, callable $callback): void
    {
        $this->calls[] = "consume {$queueName}";
    }

    /** @return list<string> */
    public function messageIds(): array
    {
        return array_map(static fn (array $p): string => (string) (json_decode($p[1], true)['messageId'] ?? ''), $this->published);
    }
}
