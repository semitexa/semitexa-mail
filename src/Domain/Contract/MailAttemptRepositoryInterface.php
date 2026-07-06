<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Application\Db\MySQL\Model\MailAttemptResource;

interface MailAttemptRepositoryInterface
{
    /**
     * Persist and RETURN the stored row (see MailRepositoryInterface::save).
     *
     * @param MailAttemptResource $entity
     */
    public function save(object $entity): MailAttemptResource;

    /**
     * @return list<MailAttemptResource>
     */
    public function findByMessageId(string $messageId): array;

    public function countByMessageId(string $messageId): int;
}
