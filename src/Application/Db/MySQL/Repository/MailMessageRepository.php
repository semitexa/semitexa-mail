<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Mail\Application\Db\MySQL\Model\MailMessageResource;
use Semitexa\Mail\Contract\MailRepositoryInterface;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Orm\Uuid\Uuid7;

#[SatisfiesRepositoryContract(of: MailRepositoryInterface::class)]
class MailMessageRepository extends AbstractRepository implements MailRepositoryInterface
{
    protected function getResourceClass(): string
    {
        return MailMessageResource::class;
    }

    public function findById(int|string $id): ?MailMessageResource
    {
        if (is_string($id) && strlen($id) === 36 && str_contains($id, '-')) {
            $id = Uuid7::toBytes($id);
        }
        return $this->select()
            ->where($this->getPkColumn(), '=', $id)
            ->fetchOneAsResource();
    }

    public function findByIdempotencyKey(string $tenantId, string $idempotencyKey): ?MailMessageResource
    {
        return $this->select()
            ->where('tenant_id', '=', $tenantId)
            ->where('idempotency_key', '=', $idempotencyKey)
            ->fetchOneAsResource();
    }

    public function save(object $entity): void
    {
        parent::save($entity);
    }
}
