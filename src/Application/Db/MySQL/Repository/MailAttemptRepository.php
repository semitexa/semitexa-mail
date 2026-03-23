<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Mail\Application\Db\MySQL\Model\MailAttemptResource;
use Semitexa\Mail\Contract\MailAttemptRepositoryInterface;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Orm\Uuid\Uuid7;

#[SatisfiesRepositoryContract(of: MailAttemptRepositoryInterface::class)]
class MailAttemptRepository extends AbstractRepository implements MailAttemptRepositoryInterface
{
    protected function getResourceClass(): string
    {
        return MailAttemptResource::class;
    }

    public function save(object $entity): void
    {
        parent::save($entity);
    }

    /**
     * @return list<MailAttemptResource>
     */
    public function findByMessageId(string $messageId): array
    {
        if (is_string($messageId) && strlen($messageId) === 36 && str_contains($messageId, '-')) {
            $messageId = Uuid7::toBytes($messageId);
        }
        return $this->select()
            ->where('mail_message_id', '=', $messageId)
            ->orderBy('attempt_no', 'ASC')
            ->fetchAllAsResource();
    }

    public function countByMessageId(string $messageId): int
    {
        if (is_string($messageId) && strlen($messageId) === 36 && str_contains($messageId, '-')) {
            $messageId = Uuid7::toBytes($messageId);
        }
        return $this->select()
            ->where('mail_message_id', '=', $messageId)
            ->count();
    }
}
