<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailMessage;

/**
 * Taking over a mail whose sender or worker died before delivering it. Kept
 * apart from MailRepositoryInterface so a project's own repository does not
 * break; without it an abandoned row keeps answering Queued.
 */
interface MailRecoveryInterface
{
    /**
     * Mark $message `pending` again if it is still `pending` or `sending` and
     * was last changed before $staleBefore. True for exactly one caller: two
     * resends of one key must not both deliver it.
     */
    public function claimAbandoned(MailMessage $message, \DateTimeImmutable $staleBefore): bool;

    /**
     * Move $message from `pending` to `sending` before a held-back delivery.
     * False when it is no longer pending: a resend took it over meanwhile.
     */
    public function takeForDelivery(MailMessage $message): bool;
}
