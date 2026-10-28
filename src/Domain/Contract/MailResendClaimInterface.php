<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailMessage;

/**
 * Taking a failed or deferred mail back for one resend. Kept apart from
 * MailRepositoryInterface so a project's own repository does not break;
 * without it, two send() calls retrying one key can both deliver it.
 */
interface MailResendClaimInterface
{
    /**
     * Move $message back to `pending`, its error cleared, if it is still in
     * the status and has the error code it was read with. True for exactly
     * one of two concurrent callers, and false for a caller whose read
     * predates another resend's outcome.
     */
    public function claimForResend(MailMessage $message): bool;
}
