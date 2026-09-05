<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailMessage;

interface MailRepositoryInterface
{
    public function findById(int|string $id): ?MailMessage;

    public function findByIdempotencyKey(string $tenantId, string $idempotencyKey): ?MailMessage;

    /**
     * Persist and RETURN the stored row — resources are readonly, so the
     * write engine's generated id/state comes back on a fresh instance.
     *
     * @param MailMessage $entity
     */
    public function save(MailMessage $entity): MailMessage;
}
