<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\AsWorkerProcessSource;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\WorkerProcessSourceInterface;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\WorkerProcess;
use Semitexa\Mail\Domain\Contract\MailerConfigResolverInterface;

/**
 * mail:work, which alone can read the mail queue: queue:work would take a
 * queued mail for an unreadable message. Nothing when the queue is in-memory,
 * where MailService sends at once instead.
 *
 * The queue is the one the mailer config resolver names, the one MailService
 * queues on. One queue only: see {@see MailerConfigResolverInterface}.
 */
#[AsService]
#[AsWorkerProcessSource]
final class MailWorkerProcesses implements WorkerProcessSourceInterface
{
    #[InjectAsReadonly]
    protected MailerConfigResolverInterface $mailerConfigResolver;

    public function workerProcesses(): array
    {
        $transport = QueueConfig::defaultTransport();
        if (QueueConfig::isProcessLocal($transport)) {
            return [];
        }

        $queue = $this->mailerConfigResolver->resolve()->queue;

        return [new WorkerProcess(['mail:work', $transport, $queue], reads: [$transport, $queue])];
    }
}
