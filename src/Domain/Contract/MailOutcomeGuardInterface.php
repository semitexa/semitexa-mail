<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailMessage;

/**
 * Writing an outcome that must not overwrite a `sent` row. Kept apart from
 * MailRepositoryInterface so a project's own repository does not break;
 * without it the worker saves the row as it is.
 */
interface MailOutcomeGuardInterface
{
    /**
     * Write a non-final status (with its error, provider id and last attempt
     * time) unless the row is already `sent`. Returns false when it was: a
     * second worker on a redelivered message delivered it meanwhile.
     */
    public function saveOutcomeUnlessSent(MailMessage $entity): bool;
}
