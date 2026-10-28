<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Queue\QueueInspection;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;

/**
 * queue:stats, queue:failed and the Queues app listed only the queue:work
 * queues: the queue mail:work reads was not among them.
 */
final class MailQueueInspectedTest extends TestCase
{
    private const TRANSPORT = 'mail-inspected-test';

    private string|false $eventsTransport = false;

    /** @var array<string, mixed> QueueTransportRegistry's statics as the test found them */
    private array $registry = [];

    protected function setUp(): void
    {
        $this->eventsTransport = getenv('EVENTS_TRANSPORT');
        foreach ((new \ReflectionClass(QueueTransportRegistry::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $this->registry[$property->getName()] = $property->getValue();
        }
    }

    protected function tearDown(): void
    {
        // Put back, not cleared: what was set before this class is there for the tests after it.
        putenv($this->eventsTransport === false ? 'EVENTS_TRANSPORT' : 'EVENTS_TRANSPORT=' . $this->eventsTransport);
        foreach ($this->registry as $name => $value) {
            (new \ReflectionProperty(QueueTransportRegistry::class, $name))->setValue(null, $value);
        }
    }

    #[Test]
    public function the_mail_queue_is_one_of_the_inspected_queues(): void
    {
        QueueTransportRegistry::register(self::TRANSPORT, new class implements QueueTransportFactoryInterface {
            public function create(): QueueTransportInterface
            {
                return new class implements QueueTransportInterface {
                    public function publish(string $queueName, string $payload): void {}
                    public function consume(string $queueName, callable $callback): void {}
                };
            }
        });
        putenv('EVENTS_TRANSPORT=' . self::TRANSPORT);

        $targets = ContainerFactory::get()->get(QueueInspection::class)->targets();
        $names = array_map(static fn (array $t): string => $t['transport'] . '/' . $t['queue'], $targets);

        self::assertContains(self::TRANSPORT . '/mail', $names);
    }
}
