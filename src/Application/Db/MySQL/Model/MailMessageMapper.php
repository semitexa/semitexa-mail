<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;

#[AsMapper(tableModel: MailMessageTableModel::class, domainModel: MailMessageResource::class)]
final class MailMessageMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof MailMessageTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        $resource = new MailMessageResource();
        foreach (get_object_vars($tableModel) as $property => $value) {
            $resource->{$property} = $value;
        }

        return $resource;
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof MailMessageResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new MailMessageTableModel(
            id: $domainModel->id,
            tenant_id: $domainModel->tenant_id,
            status: $domainModel->status,
            driver: $domainModel->driver,
            template_handle: $domainModel->template_handle,
            from_email: $domainModel->from_email,
            from_name: $domainModel->from_name,
            reply_to: $domainModel->reply_to,
            to_json: $domainModel->to_json,
            cc_json: $domainModel->cc_json,
            bcc_json: $domainModel->bcc_json,
            subject: $domainModel->subject,
            html_body: $domainModel->html_body,
            text_body: $domainModel->text_body,
            headers_json: $domainModel->headers_json,
            tags_json: $domainModel->tags_json,
            metadata_json: $domainModel->metadata_json,
            attachments_json: $domainModel->attachments_json,
            idempotency_key: $domainModel->idempotency_key,
            provider_message_id: $domainModel->provider_message_id,
            error_code: $domainModel->error_code,
            error_message: $domainModel->error_message,
            last_attempt_at: $domainModel->last_attempt_at,
            created_at: $domainModel->created_at,
            updated_at: $domainModel->updated_at,
        );
    }
}
