<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailMessage;

/**
 * Moving a mail to `sending` for the worker's recovery copy: the message the
 * lost-handoff sweep published for a mail that looked lost. The mail's first
 * message may only have been late, so the copy may take the mail only while
 * nothing has tried it. Kept apart from MailSendingClaimInterface so a
 * project's own repository does not break; without it mail:work runs no
 * lost-handoff sweep and delivers no recovery copy.
 */
interface MailRecoveryClaimInterface
{
    /**
     * Mark $message `sending`, in one write, only while it has no attempt and
     * is `queued` or `pending`, or `sending` and last changed before
     * $abandonedBefore (the worker that took it is presumed dead). True for
     * exactly one of two concurrent callers; false once a worker holds it or
     * has sent, deferred or failed it.
     */
    public function claimRecoveryForSending(MailMessage $message, \DateTimeImmutable $abandonedBefore): bool;
}
