<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailAttempt;

interface MailAttemptRepositoryInterface
{
    /**
     * Persist and RETURN the stored row (see MailRepositoryInterface::save).
     *
     * @param MailAttempt $entity
     */
    public function save(MailAttempt $entity): MailAttempt;

    /**
     * @return list<MailAttempt>
     */
    public function findByMessageId(string $messageId): array;

    public function countByMessageId(string $messageId): int;
}
