<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Mail\Application\Db\MySQL\Model\MailAttemptResource;
use Semitexa\Mail\Application\Db\MySQL\Model\MailAttemptTableModel;
use Semitexa\Mail\Contract\MailAttemptRepositoryInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Uuid\Uuid7;

#[SatisfiesRepositoryContract(of: MailAttemptRepositoryInterface::class)]
class MailAttemptRepository implements MailAttemptRepositoryInterface
{
    #[InjectAsReadonly]
    protected ?OrmManager $orm = null;

    private ?DomainRepository $repository = null;

    public function save(object $entity): void
    {
        if (!$entity instanceof MailAttemptResource) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', MailAttemptResource::class, $entity::class));
        }

        $persisted = $entity->id === ''
            ? $this->repository()->insert($entity)
            : $this->repository()->update($entity);

        $this->copyIntoMutableResource($persisted, $entity);
    }

    public function findByMessageId(string $messageId): array
    {
        /** @var list<MailAttemptResource> */
        return $this->repository()->query()
            ->where(MailAttemptTableModel::column('mail_message_id'), Operator::Equals, Uuid7::toBytes($messageId))
            ->orderBy(MailAttemptTableModel::column('attempt_no'), Direction::Asc)
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
            MailAttemptTableModel::class,
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

    private function copyIntoMutableResource(object $source, MailAttemptResource $target): void
    {
        $source instanceof MailAttemptResource || throw new \InvalidArgumentException('Unexpected persisted resource.');

        foreach (get_object_vars($source) as $property => $value) {
            $target->{$property} = $value;
        }
    }
}
