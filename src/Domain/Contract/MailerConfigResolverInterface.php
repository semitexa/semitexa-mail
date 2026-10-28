<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailerConfig;

/**
 * The mailer a mail is sent with, per tenant and mailer key.
 *
 * The queue in it is where MailService queues the mail. The worker container
 * runs one mail:work, on the queue resolve() names with no tenant and no
 * mailer key: a resolver that gives some tenants or mailers a queue of their
 * own must also name those queues to the worker (a WorkerProcessSourceInterface
 * of its own, with #[AsWorkerProcessSource]), or their mail stays queued.
 */
interface MailerConfigResolverInterface
{
    public function resolve(?string $tenantId = null, ?string $mailerKey = null): MailerConfig;
}
