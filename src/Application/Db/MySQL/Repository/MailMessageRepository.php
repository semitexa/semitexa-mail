<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Mail\Application\Db\MySQL\Model\MailMessageResource;
use Semitexa\Mail\Contract\MailRepositoryInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;

#[SatisfiesRepositoryContract(of: MailRepositoryInterface::class)]
class MailMessageRepository implements MailRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    public function findById(int|string $id): ?MailMessageResource
    {
        if (!is_string($id)) {
            $id = (string) $id;
        }

        /** @var MailMessageResource|null */
        return $this->repository()->findById($id);
    }

    public function findByIdempotencyKey(string $tenantId, string $idempotencyKey): ?MailMessageResource
    {
        /** @var MailMessageResource|null */
        return $this->repository()->query()
            ->where(MailMessageResource::column('tenant_id'), Operator::Equals, $tenantId)
            ->where(MailMessageResource::column('idempotency_key'), Operator::Equals, $idempotencyKey)
            ->fetchOneAs(MailMessageResource::class, $this->orm()->getMapperRegistry());
    }

    public function save(object $entity): void
    {
        if (!$entity instanceof MailMessageResource) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', MailMessageResource::class, $entity::class));
        }

        $persisted = $entity->id === ''
            ? $this->repository()->insert($entity)
            : $this->repository()->update($entity);

        $this->copyIntoMutableResource($persisted, $entity);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            MailMessageResource::class,
            MailMessageResource::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    private function copyIntoMutableResource(object $source, MailMessageResource $target): void
    {
        $source instanceof MailMessageResource || throw new \InvalidArgumentException('Unexpected persisted resource.');

        foreach (get_object_vars($source) as $property => $value) {
            $target->{$property} = $value;
        }
    }
}
