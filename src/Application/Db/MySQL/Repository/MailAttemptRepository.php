<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Mail\Application\Db\MySQL\Model\MailAttemptResource;
use Semitexa\Mail\Domain\Contract\MailAttemptRepositoryInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Application\Service\Uuid7;

#[SatisfiesRepositoryContract(of: MailAttemptRepositoryInterface::class)]
class MailAttemptRepository implements MailAttemptRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    private ?DomainRepository $system = null;

    public function save(object $entity): MailAttemptResource
    {
        if (!$entity instanceof MailAttemptResource) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', MailAttemptResource::class, $entity::class));
        }

        /** @var MailAttemptResource */
        return $entity->id === ''
            ? $this->system()->insert($entity)
            : $this->system()->update($entity);
    }

    /**
     * SYSTEM-scope view — deliberately cross-tenant: attempts are addressed by
     * their message id (infrastructure), never by a tenant surface. The
     * resource is #[TenantScoped] so future finders fail closed.
     */
    private function system(): DomainRepository
    {
        return $this->system ??= $this->repository()->withoutTenantScope(SystemScopeToken::issue());
    }

    public function findByMessageId(string $messageId): array
    {
        /** @var list<MailAttemptResource> */
        return $this->system()->query()
            ->where(MailAttemptResource::column('mail_message_id'), Operator::Equals, Uuid7::toBytes($messageId))
            ->orderBy(MailAttemptResource::column('attempt_no'), Direction::Asc)
            ->fetchAllAs(MailAttemptResource::class, $this->orm()->getMapperRegistry());
    }

    public function countByMessageId(string $messageId): int
    {
        $result = $this->adapter()->execute(
            'SELECT COUNT(*) AS cnt FROM mail_attempts WHERE mail_message_id = :message_id',
            ['message_id' => Uuid7::toBytes($messageId)],
        );

        return (int) ($result->rows[0]['cnt'] ?? 0);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            MailAttemptResource::class,
            MailAttemptResource::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    private function adapter(): \Semitexa\Orm\Adapter\DatabaseAdapterInterface
    {
        return $this->orm()->getAdapter();
    }

}
