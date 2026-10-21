<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

interface MailRetentionInterface
{
    /**
     * Delete up to $limit (at most 500) sent or failed messages last changed
     * before $cutoff, with their attempts. Returns the messages deleted.
     */
    public function deleteFinishedBefore(\DateTimeImmutable $cutoff, int $limit): int;
}
