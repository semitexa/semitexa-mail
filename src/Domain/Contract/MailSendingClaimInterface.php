<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailMessage;

/**
 * Moving a mail to `sending` for one worker at a time. Kept apart from
 * MailRepositoryInterface so a project's own repository does not break;
 * without it, two workers on a redelivered message can both deliver it.
 */
interface MailSendingClaimInterface
{
    /**
     * Mark $message `sending` unless it is `sent`, or `sending` and last
     * changed at or after $staleBefore (another worker is on it). True for
     * exactly one of two concurrent callers.
     */
    public function claimForSending(MailMessage $message, \DateTimeImmutable $staleBefore): bool;
}
