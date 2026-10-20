<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\AsWorkerProcessSource;
use Semitexa\Core\Contract\WorkerProcessSourceInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Queue\QueueConfig;
use Semitexa\Core\Queue\WorkerProcess;

/**
 * mail:work, which alone can read the mail queue: queue:work would take a
 * queued mail for an unreadable message. Nothing when the queue is in-memory,
 * where MailService sends at once instead.
 */
#[AsService]
#[AsWorkerProcessSource]
final class MailWorkerProcesses implements WorkerProcessSourceInterface
{
    public function workerProcesses(): array
    {
        $transport = QueueConfig::defaultTransport();
        if (QueueConfig::isProcessLocal($transport)) {
            return [];
        }

        return [new WorkerProcess(['mail:work', $transport, (string) (Environment::getEnvValue('MAIL_QUEUE', 'mail') ?: 'mail')])];
    }
}
