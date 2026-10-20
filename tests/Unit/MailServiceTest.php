<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Mail\Application\Service\AttachmentResolver;
use Semitexa\Mail\Application\Service\FakeMailTransport;
use Semitexa\Mail\Application\Service\MailService;
use Semitexa\Mail\Application\Service\MailTransportRegistry;
use Semitexa\Mail\Application\Service\MailWorker;
use Semitexa\Mail\Domain\Contract\MailAttemptRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailerConfigResolverInterface;
use Semitexa\Mail\Domain\Contract\MailRepositoryInterface;
use Semitexa\Mail\Domain\Contract\MailTemplateRendererInterface;
use Semitexa\Mail\Domain\Contract\MailTransportInterface;
use Semitexa\Mail\Domain\Enum\MailDispatchStatus;
use Semitexa\Mail\Domain\Enum\MailSendMode;
use Semitexa\Mail\Domain\Enum\MailTransportStatus;
use Semitexa\Mail\Domain\Model\MailAttempt;
use Semitexa\Mail\Domain\Model\MailEnvelope;
use Semitexa\Mail\Domain\Model\MailerConfig;
use Semitexa\Mail\Domain\Model\MailMessage;
use Semitexa\Mail\Domain\Model\MailRecipient;
use Semitexa\Mail\Domain\Model\MailSendOptions;
use Semitexa\Mail\Domain\Model\MailTransportResult;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;
use Semitexa\Mail\Domain\Model\QueuedMailMessage;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Sending mail, proven through MailService and MailWorker themselves
 * (tk-parity-proof-gaps): until this test only header injection and the
 * sandbox transport were covered, and FakeMailTransport — the package's own
 * test double — was used by nothing.
 */
final class MailServiceTest extends TestCase
{
    private MailServiceMessages $messages;

    private MailServiceAttempts $attempts;

    private FakeMailTransport $fake;

    private string|false $eventsAsync = false;

    private string|false $eventsTransport = false;

    protected function setUp(): void
    {
        MailTransportRegistry::reset();
        $this->fake = new FakeMailTransport();
        MailTransportRegistry::register($this->fake);
        $this->messages = new MailServiceMessages();
        $this->attempts = new MailServiceAttempts();
        QueueTransportRegistry::reset();
        MailServiceQueueSpy::$published = [];
        $this->eventsAsync = getenv('EVENTS_ASYNC');
        $this->eventsTransport = getenv('EVENTS_TRANSPORT');
    }

    protected function tearDown(): void
    {
        MailTransportRegistry::reset();
        QueueTransportRegistry::reset();
        putenv($this->eventsTransport === false ? 'EVENTS_TRANSPORT' : 'EVENTS_TRANSPORT=' . $this->eventsTransport);
        putenv($this->eventsAsync === false ? 'EVENTS_ASYNC' : 'EVENTS_ASYNC=' . $this->eventsAsync);
    }

    #[Test]
    public function a_sync_send_delivers_through_the_transport_and_records_it(): void
    {
        $result = $this->service()->send($this->envelope(subject: 'Welcome', text: 'Hello Ada'), $this->now());

        self::assertSame(MailDispatchStatus::Sent, $result->status);
        self::assertSame(1, $this->fake->count());
        $sent = $this->fake->getLastMessage();
        self::assertSame('Welcome', $sent?->subject);
        self::assertSame('Hello Ada', $sent?->textBody);
        self::assertSame('noreply@example.test', $sent?->from->email, 'the configured sender when the envelope names none');
        self::assertSame(['ada@example.test'], array_map(static fn (MailRecipient $r): string => $r->email, $sent?->to ?? []));
        self::assertSame('sent', $this->messages->byId($result->messageId)?->getStatus());
        self::assertCount(1, $this->attempts->saved, 'every delivery attempt is recorded');
    }

    #[Test]
    public function a_template_renders_the_subject_and_bodies(): void
    {
        $envelope = $this->envelope();
        $envelope->templateHandle = 'welcome';
        $envelope->variables = ['name' => 'Ada'];

        $this->service()->send($envelope, $this->now());

        self::assertSame('Welcome, Ada', $this->fake->getLastMessage()?->subject);
        self::assertSame('<p>Hi Ada</p>', $this->fake->getLastMessage()?->htmlBody);
    }

    #[Test]
    public function the_same_idempotency_key_sends_once(): void
    {
        $first = $this->envelope(subject: 'Receipt', text: 'Paid');
        $first->tenantId = 't-1';
        $first->idempotencyKey = 'order-42-receipt';
        $again = clone $first;

        $one = $this->service()->send($first, $this->now());
        $two = $this->service()->send($again, $this->now());

        self::assertSame(1, $this->fake->count(), 'a retried request must not email twice');
        self::assertSame($one->messageId, $two->messageId);
    }

    #[Test]
    public function a_queued_send_publishes_the_message_and_does_not_deliver_yet(): void
    {
        $this->spyOnTheQueue();

        $result = $this->service()->send($this->envelope(subject: 'Later', text: 'x'), new MailSendOptions(mode: MailSendMode::Queued));

        self::assertSame(MailDispatchStatus::Queued, $result->status);
        self::assertSame(0, $this->fake->count());
        self::assertCount(1, MailServiceQueueSpy::$published);
        self::assertSame('mail', MailServiceQueueSpy::$published[0][0]);
        self::assertStringContainsString((string) $result->messageId, MailServiceQueueSpy::$published[0][1]);
        self::assertSame('queued', $this->messages->byId($result->messageId)?->getStatus());
    }

    /**
     * REGRESSION (tk-queued-mail-sync-without-async). With EVENTS_ASYNC=0 the
     * queue is in-memory, one per process, and no mail:work can read it: a
     * queued mail was marked queued and never sent. Without async events it
     * is sent at once, and the result says sent.
     */
    #[Test]
    public function without_async_events_a_queued_send_is_delivered_at_once(): void
    {
        putenv('EVENTS_ASYNC=0');
        putenv('EVENTS_TRANSPORT');

        $result = $this->service()->send($this->envelope(subject: 'Later', text: 'Hello now'), new MailSendOptions(mode: MailSendMode::Queued));

        self::assertSame(MailDispatchStatus::Sent, $result->status);
        self::assertSame(1, $this->fake->count());
        self::assertSame('Hello now', $this->fake->getLastMessage()?->textBody);
        self::assertSame('sent', $this->messages->byId($result->messageId)?->getStatus());
    }

    #[Test]
    public function a_refused_delivery_is_failed_and_says_why(): void
    {
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Rejected, errorCode: 'mailbox_full', errorMessage: '552 mailbox full')));

        $result = $this->service(driver: 'scripted')->send($this->envelope(subject: 'Nope', text: 'x'), $this->now());

        self::assertSame(MailDispatchStatus::Failed, $result->status);
        self::assertSame('mailbox_full', $result->errorCode);
        self::assertSame('failed', $this->messages->byId($result->messageId)?->getStatus());
        self::assertSame('mailbox_full', $this->attempts->saved[0]->getErrorCode());
    }

    #[Test]
    public function the_worker_delivers_what_the_service_queued(): void
    {
        $this->spyOnTheQueue();
        $queued = $this->service()->send($this->envelope(subject: 'Later', text: 'Hello later'), new MailSendOptions(mode: MailSendMode::Queued));

        $this->worker()->processPayload(MailServiceQueueSpy::$published[0][1]);

        self::assertSame('Later', $this->fake->getLastMessage()?->subject);
        self::assertSame('noreply@example.test', $this->fake->getLastMessage()?->from->email);
        self::assertSame('sent', $this->messages->byId($queued->messageId)?->getStatus());
        self::assertCount(1, $this->attempts->saved);
    }

    #[Test]
    public function a_redelivered_queue_message_does_not_send_twice(): void
    {
        $this->spyOnTheQueue();
        $this->service()->send($this->envelope(subject: 'Once', text: 'x'), new MailSendOptions(mode: MailSendMode::Queued));
        $payload = MailServiceQueueSpy::$published[0][1];

        $this->worker()->processPayload($payload);
        $this->worker()->processPayload($payload);

        self::assertSame(1, $this->fake->count(), 'at-least-once delivery from the queue must not mean two emails');
    }

    #[Test]
    public function a_temporary_failure_goes_back_on_the_queue_with_one_more_attempt(): void
    {
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'timeout', errorMessage: 'SMTP timed out')));

        $this->worker(driver: 'scripted')->processPayload((new QueuedMailMessage(messageId: $id, attempts: 0, maxRetries: 2, retryDelay: 0))->toJson());

        self::assertSame('deferred', $this->messages->byId($id)?->getStatus());
        self::assertCount(1, MailServiceQueueSpy::$published);
        self::assertSame('mail', MailServiceQueueSpy::$published[0][0]);
        self::assertSame(1, QueuedMailMessage::fromJson(MailServiceQueueSpy::$published[0][1])->attempts);
    }

    #[Test]
    public function a_permanent_failure_is_not_retried(): void
    {
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Rejected, errorCode: 'invalid_recipient', errorMessage: '550 no such user')));

        $this->worker(driver: 'scripted')->processPayload((new QueuedMailMessage(messageId: $id, maxRetries: 2, retryDelay: 0))->toJson());

        self::assertSame('failed', $this->messages->byId($id)?->getStatus());
        self::assertSame('invalid_recipient', $this->messages->byId($id)?->getErrorCode());
        self::assertSame([], MailServiceQueueSpy::$published);
    }

    #[Test]
    public function a_temporary_failure_on_the_last_attempt_fails_for_good(): void
    {
        $this->spyOnTheQueue();
        $id = $this->queuedMessage();
        MailTransportRegistry::register(new MailServiceScriptedTransport(new MailTransportResult(status: MailTransportStatus::Failed, errorCode: 'timeout', errorMessage: 'SMTP timed out')));

        $this->worker(driver: 'scripted')->processPayload((new QueuedMailMessage(messageId: $id, attempts: 2, maxRetries: 2, retryDelay: 0))->toJson());

        self::assertSame('failed', $this->messages->byId($id)?->getStatus());
        self::assertSame([], MailServiceQueueSpy::$published);
    }

    private function spyOnTheQueue(): void
    {
        QueueTransportRegistry::register('mail-test-spy', new MailServiceQueueSpyFactory());
        putenv('EVENTS_TRANSPORT=mail-test-spy');
    }

    /** A message as MailService leaves it in the store; what it published is cleared. */
    private function queuedMessage(): string
    {
        $id = (string) $this->service()->send($this->envelope(subject: 'Retry me', text: 'x'), new MailSendOptions(mode: MailSendMode::Queued))->messageId;
        MailServiceQueueSpy::$published = [];

        return $id;
    }

    private function worker(string $driver = 'fake'): MailWorker
    {
        $worker = new MailWorker($this->messages, $this->attempts, $this->config($driver), (new \ReflectionClass(AttachmentResolver::class))->newInstanceWithoutConstructor());
        $worker->setOutput(new NullOutput());

        return $worker;
    }

    private function config(string $driver): MailServiceConfig
    {
        return new MailServiceConfig(new MailerConfig(driver: $driver, fromEmail: 'noreply@example.test', queue: 'mail'));
    }

    /** Delivered in the call: MailSendOptions queues unless told otherwise. */
    private function now(): MailSendOptions
    {
        return new MailSendOptions(mode: MailSendMode::Sync);
    }

    private function service(string $driver = 'fake'): MailService
    {
        $service = new MailService();
        foreach ([
            'configResolver' => $this->config($driver),
            'renderer' => new MailServiceRenderer(),
            'mailRepository' => $this->messages,
            'attemptRepository' => $this->attempts,
            'attachmentResolver' => (new \ReflectionClass(AttachmentResolver::class))->newInstanceWithoutConstructor(),
        ] as $property => $value) {
            (new \ReflectionProperty(MailService::class, $property))->setValue($service, $value);
        }

        return $service;
    }

    private function envelope(?string $subject = null, ?string $text = null): MailEnvelope
    {
        $envelope = new MailEnvelope();
        $envelope->to = [new MailRecipient('ada@example.test', 'Ada')];
        $envelope->subject = $subject;
        $envelope->textBody = $text;

        return $envelope;
    }
}

final class MailServiceConfig implements MailerConfigResolverInterface
{
    public function __construct(private readonly MailerConfig $config)
    {
    }

    public function resolve(?string $tenantId = null, ?string $mailerKey = null): MailerConfig
    {
        return $this->config;
    }
}

final class MailServiceRenderer implements MailTemplateRendererInterface
{
    public function render(string $templateHandle, array $variables, ?string $locale = null): array
    {
        return ['subject' => 'Welcome, ' . $variables['name'], 'htmlBody' => '<p>Hi ' . $variables['name'] . '</p>', 'textBody' => 'Hi ' . $variables['name']];
    }
}

final class MailServiceMessages implements MailRepositoryInterface
{
    /** @var array<string, MailMessage> */
    private array $byId = [];

    private int $next = 0;

    public function byId(?string $id): ?MailMessage
    {
        return $id === null ? null : ($this->byId[$id] ?? null);
    }

    public function findById(int|string $id): ?MailMessage
    {
        return $this->byId[(string) $id] ?? null;
    }

    public function findByIdempotencyKey(string $tenantId, string $idempotencyKey): ?MailMessage
    {
        foreach ($this->byId as $message) {
            if ($message->getTenantId() === $tenantId && $message->getIdempotencyKey() === $idempotencyKey) {
                return $message;
            }
        }

        return null;
    }

    public function save(MailMessage $entity): MailMessage
    {
        $saved = $entity->getId() === '' ? $entity->with(['id' => 'm-' . ++$this->next]) : $entity;
        $this->byId[$saved->getId()] = $saved;

        return $saved;
    }
}

final class MailServiceAttempts implements MailAttemptRepositoryInterface
{
    /** @var list<MailAttempt> */
    public array $saved = [];

    public function save(MailAttempt $entity): MailAttempt
    {
        $this->saved[] = $entity;

        return $entity;
    }

    public function findByMessageId(string $messageId): array
    {
        return $this->saved;
    }

    public function countByMessageId(string $messageId): int
    {
        return count($this->saved);
    }
}

final class MailServiceScriptedTransport implements MailTransportInterface
{
    public function __construct(private readonly MailTransportResult $result)
    {
    }

    public function key(): string
    {
        return 'scripted';
    }

    public function deliver(PreparedMailMessage $message, MailerConfig $config): MailTransportResult
    {
        return $this->result;
    }
}

final class MailServiceQueueSpy implements QueueTransportInterface
{
    /** @var list<array{string, string}> */
    public static array $published = [];

    public function publish(string $queueName, string $payload): void
    {
        self::$published[] = [$queueName, $payload];
    }

    public function consume(string $queueName, callable $callback): void
    {
    }
}

final class MailServiceQueueSpyFactory implements QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface
    {
        return new MailServiceQueueSpy();
    }
}
