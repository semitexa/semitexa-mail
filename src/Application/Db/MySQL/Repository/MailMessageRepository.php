<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Mail\Application\Db\MySQL\Model\MailMessageResource;
use Semitexa\Mail\Domain\Model\MailMessage;
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

    public function findById(int|string $id): ?MailMessage
    {
        if (!is_string($id)) {
            $id = (string) $id;
        }

        /** @var MailMessage|null */
        return $this->system()->findById($id);
    }

    public function findByIdempotencyKey(string $tenantId, string $idempotencyKey): ?MailMessage
    {
        /** @var MailMessage|null */
        return $this->repository()->forTenant($tenantId)->query()
            ->where(MailMessageResource::column('idempotency_key'), Operator::Equals, $idempotencyKey)
            ->fetchOneAs(MailMessage::class, $this->orm()->getMapperRegistry());
    }

    public function save(MailMessage $entity): MailMessage
    {

        // First save stamps the creation time; every save refreshes the update.
        $now = new \DateTimeImmutable();
        $entity = $entity->with(['createdAt' => $entity->getCreatedAt() ?? $now, 'updatedAt' => $now]);

        /** @var MailMessage */
        return $entity->getId() === ''
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
            MailMessage::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

}
