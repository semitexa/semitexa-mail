<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailMessage;

interface MailRepositoryInterface
{
    public function findById(int|string $id): ?MailMessage;

    public function findByIdempotencyKey(?string $tenantId, string $idempotencyKey): ?MailMessage;

    /**
     * Persist and RETURN the stored row — resources are readonly, so the
     * write engine's generated id/state comes back on a fresh instance.
     *
     * @param MailMessage $entity
     */
    public function save(MailMessage $entity): MailMessage;

    /**
     * Write a non-final status (with its error, provider id and last attempt
     * time) unless the row is already `sent`. Returns false when it was: a second worker on a
     * redelivered message delivered it meanwhile.
     */
    public function saveOutcomeUnlessSent(MailMessage $entity): bool;
}
