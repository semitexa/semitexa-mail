<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;

#[AsMapper(tableModel: MailAttemptTableModel::class, domainModel: MailAttemptResource::class)]
final class MailAttemptMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof MailAttemptTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        $resource = new MailAttemptResource();
        foreach (get_object_vars($tableModel) as $property => $value) {
            $resource->{$property} = $value;
        }

        return $resource;
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof MailAttemptResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new MailAttemptTableModel(
            id: $domainModel->id,
            tenant_id: $domainModel->tenant_id,
            mail_message_id: $domainModel->mail_message_id,
            attempt_no: $domainModel->attempt_no,
            driver: $domainModel->driver,
            status: $domainModel->status,
            started_at: $domainModel->started_at,
            finished_at: $domainModel->finished_at,
            provider_message_id: $domainModel->provider_message_id,
            provider_status: $domainModel->provider_status,
            provider_response_json: $domainModel->provider_response_json,
            error_code: $domainModel->error_code,
            error_message: $domainModel->error_message,
        );
    }
}
