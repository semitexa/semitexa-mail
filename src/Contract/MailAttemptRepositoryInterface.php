<?php

declare(strict_types=1);

namespace Semitexa\Mail\Contract;

use Semitexa\Mail\Application\Db\MySQL\Model\MailAttemptResource;

interface MailAttemptRepositoryInterface
{
    public function save(MailAttemptResource $resource): void;

    /**
     * @return list<MailAttemptResource>
     */
    public function findByMessageId(string $messageId): array;

    public function countByMessageId(string $messageId): int;
}
