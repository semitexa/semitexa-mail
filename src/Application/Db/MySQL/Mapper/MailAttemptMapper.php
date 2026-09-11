<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Mapper;

use Semitexa\Mail\Application\Db\MySQL\Model\MailAttemptResource;
use Semitexa\Mail\Domain\Model\MailAttempt;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

/**
 * The bridge between the MySQL row and one delivery attempt.
 *
 * One real conversion, and it is the provider response: a JSON string in the
 * column, an array on the attempt. `mail_message_id` is NOT one — it is a
 * BINARY(16) column, which the ORM's TypeCaster turns into a canonical uuid
 * string on the way out and back into 16 bytes on the way in. This mapper used
 * to convert it a second time in both directions; the read side would have
 * thrown «Expected 16 bytes, got 36» the first time anything read an attempt
 * back, and the write side worked only by accident. See the scheduler's history
 * mapper, where the same pair took the whole worker down, and
 * `semitexa.mapperTypeConversion`, which now refuses the shape.
 */
#[AsMapper(resourceModel: MailAttemptResource::class, domainModel: MailAttempt::class)]
final class MailAttemptMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof MailAttemptResource || throw new \InvalidArgumentException('Unexpected resource model.');

        $decoded = $resourceModel->provider_response_json === null
            ? null
            : json_decode($resourceModel->provider_response_json, true);
        $response = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $response[$key] = $value;
                }
            }
        }

        return new MailAttempt(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenant_id,
            mailMessageId: $resourceModel->mail_message_id,
            attemptNo: $resourceModel->attempt_no,
            driver: $resourceModel->driver,
            status: $resourceModel->status,
            startedAt: $resourceModel->started_at,
            finishedAt: $resourceModel->finished_at,
            providerMessageId: $resourceModel->provider_message_id,
            providerStatus: $resourceModel->provider_status,
            providerResponse: $response,
            errorCode: $resourceModel->error_code,
            errorMessage: $resourceModel->error_message,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof MailAttempt || throw new \InvalidArgumentException('Unexpected domain model.');

        return new MailAttemptResource(
            id: $domainModel->getId(),
            tenant_id: $domainModel->getTenantId(),
            mail_message_id: $domainModel->getMailMessageId(),
            attempt_no: $domainModel->getAttemptNo(),
            driver: $domainModel->getDriver(),
            status: $domainModel->getStatus(),
            started_at: $domainModel->getStartedAt(),
            finished_at: $domainModel->getFinishedAt(),
            provider_message_id: $domainModel->getProviderMessageId(),
            provider_status: $domainModel->getProviderStatus(),
            provider_response_json: $domainModel->getProviderResponse() === []
                ? null
                : (string) json_encode($domainModel->getProviderResponse(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            error_code: $domainModel->getErrorCode(),
            error_message: $domainModel->getErrorMessage(),
        );
    }
}
