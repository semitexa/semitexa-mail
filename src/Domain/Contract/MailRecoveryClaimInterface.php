<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailMessage;

/**
 * Moving a mail to `sending` for the worker's recovery copy: the message the
 * lost-handoff sweep published for a mail that looked lost. The mail's first
 * message may only have been late, so the copy may take the mail only while
 * nothing has tried it. Kept apart from MailSendingClaimInterface so a
 * project's own repository does not break; without it the worker reads the
 * status and then claims, and a mail that failed in between is sent again.
 */
interface MailRecoveryClaimInterface
{
    /**
     * Mark $message `sending` only while it is `queued` or `pending` with no
     * attempt, in one write: true for exactly one of two concurrent callers,
     * false once any worker has taken, sent, deferred or failed it.
     */
    public function claimRecoveryForSending(MailMessage $message): bool;
}
