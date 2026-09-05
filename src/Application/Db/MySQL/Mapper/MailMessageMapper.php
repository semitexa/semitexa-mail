<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Mapper;

use Semitexa\Mail\Application\Db\MySQL\Model\MailMessageResource;
use Semitexa\Mail\Domain\Model\MailMessage;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

/**
 * The bridge between the MySQL row and the message.
 *
 * Seven JSON columns are decoded here — recipients three ways, headers, tags,
 * metadata, attachments. Both MailWorker and MailService were doing that by
 * hand, the same four decodes written twice, because the row was standing in
 * as the model.
 */
#[AsMapper(resourceModel: MailMessageResource::class, domainModel: MailMessage::class)]
final class MailMessageMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof MailMessageResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new MailMessage(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            status: $resourceModel->status,
            driver: $resourceModel->driver,
            fromEmail: $resourceModel->from_email,
            subject: $resourceModel->subject,
            to: $this->recipients($resourceModel->to_json),
            cc: $this->recipients($resourceModel->cc_json),
            bcc: $this->recipients($resourceModel->bcc_json),
            templateHandle: $resourceModel->template_handle,
            fromName: $resourceModel->from_name,
            replyTo: $resourceModel->reply_to,
            htmlBody: $resourceModel->html_body,
            textBody: $resourceModel->text_body,
            headers: $this->strings2($resourceModel->headers_json),
            tags: $this->strings($resourceModel->tags_json),
            metadata: $this->map($resourceModel->metadata_json),
            attachments: $this->rows($resourceModel->attachments_json),
            idempotencyKey: $resourceModel->idempotency_key,
            providerMessageId: $resourceModel->provider_message_id,
            errorCode: $resourceModel->error_code,
            errorMessage: $resourceModel->error_message,
            lastAttemptAt: $resourceModel->last_attempt_at,
            createdAt: $resourceModel->created_at,
            updatedAt: $resourceModel->updated_at,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof MailMessage || throw new \InvalidArgumentException('Unexpected domain model.');

        $json = static fn (array $value): ?string => $value === []
            ? null
            : (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return new MailMessageResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            status: $domainModel->getStatus(),
            driver: $domainModel->getDriver(),
            template_handle: $domainModel->getTemplateHandle(),
            from_email: $domainModel->getFromEmail(),
            from_name: $domainModel->getFromName(),
            reply_to: $domainModel->getReplyTo(),
            // The recipient list is never absent: an empty one is still "[]".
            to_json: $json($domainModel->getTo()) ?? '[]',
            cc_json: $json($domainModel->getCc()),
            bcc_json: $json($domainModel->getBcc()),
            subject: $domainModel->getSubject(),
            html_body: $domainModel->getHtmlBody(),
            text_body: $domainModel->getTextBody(),
            headers_json: $json($domainModel->getHeaders()),
            tags_json: $json($domainModel->getTags()),
            metadata_json: $json($domainModel->getMetadata()),
            attachments_json: $json($domainModel->getAttachments()),
            idempotency_key: $domainModel->getIdempotencyKey(),
            provider_message_id: $domainModel->getProviderMessageId(),
            error_code: $domainModel->getErrorCode(),
            error_message: $domainModel->getErrorMessage(),
            last_attempt_at: $domainModel->getLastAttemptAt(),
            created_at: $domainModel->getCreatedAt(),
            updated_at: $domainModel->getUpdatedAt(),
        );
    }

    /**
     * Recipients as the transports expect them. A row missing an email is
     * dropped rather than passed on: a recipient without an address is not a
     * recipient, and the driver would only reject the whole message.
     *
     * @return list<array{email: string, name?: string|null}>
     */
    private function recipients(?string $json): array
    {
        $out = [];
        foreach ($this->rows($json) as $row) {
            if (!isset($row['email']) || !is_string($row['email'])) {
                continue;
            }
            $name = $row['name'] ?? null;
            $out[] = ['email' => $row['email'], 'name' => is_string($name) ? $name : null];
        }

        return $out;
    }

    /**
     * Headers are strings on the wire; anything else in the column is a
     * corrupted row and would break the transport, not the message.
     *
     * @return array<string, string>
     */
    private function strings2(?string $json): array
    {
        $out = [];
        foreach ($this->map($json) as $key => $value) {
            if (is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function rows(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($decoded, 'is_array'));

        return $rows;
    }

    /** @return array<string, mixed> */
    private function map(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function strings(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
