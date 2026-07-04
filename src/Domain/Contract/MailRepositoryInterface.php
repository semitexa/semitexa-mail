<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Application\Db\MySQL\Model\MailMessageResource;

interface MailRepositoryInterface
{
    public function findById(int|string $id): ?MailMessageResource;

    public function findByIdempotencyKey(string $tenantId, string $idempotencyKey): ?MailMessageResource;

    /**
     * Persist and RETURN the stored row — resources are readonly, so the
     * write engine's generated id/state comes back on a fresh instance.
     *
     * @param MailMessageResource $entity
     */
    public function save(object $entity): MailMessageResource;
}
