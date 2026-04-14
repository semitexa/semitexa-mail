<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;

#[AsMapper(resourceModel: MailAttemptResource::class, domainModel: MailAttemptResource::class)]
final class MailAttemptMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof MailAttemptResource || throw new \InvalidArgumentException('Unexpected resource model.');
        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof MailAttemptResource || throw new \InvalidArgumentException('Unexpected resource model.');
        return clone $domainModel;
    }
}
