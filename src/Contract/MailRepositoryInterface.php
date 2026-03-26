<?php

declare(strict_types=1);

namespace Semitexa\Mail\Contract;

use Semitexa\Mail\Application\Db\MySQL\Model\MailMessageResource;

interface MailRepositoryInterface
{
    public function findById(int|string $id): ?MailMessageResource;

    public function findByIdempotencyKey(string $tenantId, string $idempotencyKey): ?MailMessageResource;

    public function save(object $entity): void;
}
