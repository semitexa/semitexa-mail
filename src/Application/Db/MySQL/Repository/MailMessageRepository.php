<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Mail\Application\Db\MySQL\Model\MailMessageResource;
use Semitexa\Mail\Domain\Contract\MailRepositoryInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Repository\DomainRepository;

#[SatisfiesRepositoryContract(of: MailRepositoryInterface::class)]
class MailMessageRepository implements MailRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    private ?DomainRepository $system = null;

    public function findById(int|string $id): ?MailMessageResource
    {
        if (!is_string($id)) {
            $id = (string) $id;
        }

        /** @var MailMessageResource|null */
        return $this->system()->findById($id);
    }

    public function findByIdempotencyKey(string $tenantId, string $idempotencyKey): ?MailMessageResource
    {
        /** @var MailMessageResource|null */
        return $this->repository()->forTenant($tenantId)->query()
            ->where(MailMessageResource::column('idempotency_key'), Operator::Equals, $idempotencyKey)
            ->fetchOneAs(MailMessageResource::class, $this->orm()->getMapperRegistry());
    }

    public function save(object $entity): MailMessageResource
    {
        if (!$entity instanceof MailMessageResource) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', MailMessageResource::class, $entity::class));
        }

        // First save stamps created_at; every save refreshes updated_at.
        $now = new \DateTimeImmutable();
        $entity = $entity->copyWith(['created_at' => $entity->created_at ?? $now, 'updated_at' => $now]);

        /** @var MailMessageResource */
        return $entity->id === ''
            ? $this->system()->insert($entity)
            : $this->system()->update($entity);
    }

    /**
     * SYSTEM-scope view — deliberately cross-tenant: the worker addresses a
     * message by its own unique id (from the queue payload), and writes carry
     * the tenant on the row itself. The resource is #[TenantScoped], so any
     * FUTURE tenant-facing finder must call forTenant(...) (see
     * findByIdempotencyKey) or copy this token-marked posture consciously.
     */
    private function system(): DomainRepository
    {
        return $this->system ??= $this->repository()->withoutTenantScope(SystemScopeToken::issue());
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

}
