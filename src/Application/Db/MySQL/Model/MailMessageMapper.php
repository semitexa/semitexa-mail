<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;

#[AsMapper(resourceModel: MailMessageResource::class, domainModel: MailMessageResource::class)]
final class MailMessageMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof MailMessageResource || throw new \InvalidArgumentException('Unexpected resource model.');
        return clone $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof MailMessageResource || throw new \InvalidArgumentException('Unexpected domain model.');
        return clone $domainModel;
    }
}
