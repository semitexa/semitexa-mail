<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit\Db;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mail\Application\Db\MySQL\Mapper\MailAttemptMapper;
use Semitexa\Mail\Application\Db\MySQL\Model\MailAttemptResource;
use Semitexa\Mail\Domain\Model\MailAttempt;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Application\Service\Hydration\TypeCaster;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Domain\Model\ColumnDefinition;

/**
 * The attempt row has to survive the trip the ORM actually takes it on.
 *
 * This mapper carried the same latent defect the scheduler's history mapper
 * died of: it converted `mail_message_id` itself, in both directions, on a
 * BINARY(16) column the ORM already converts. Mapper-out then mapper-in
 * cancelled, so a plain round trip would have stayed green — what exposes it is
 * putting the real TypeCaster between the two halves, which is what the write
 * engine and the hydrator do in production.
 *
 * It never fired only because nothing read an attempt back often enough to
 * notice. That is not a reason to leave it: `MailAttemptRepository::findByMessageId`
 * reaches it, and so does `save()`, which maps the persisted row back.
 */
final class MailAttemptMapperTest extends TestCase
{
    private function attempt(string $mailMessageId): MailAttempt
    {
        return new MailAttempt(
            id: Uuid7::generate(),
            tenantId: 'tenant-1',
            mailMessageId: $mailMessageId,
            attemptNo: 2,
            driver: 'smtp',
            status: 'failed',
            startedAt: new \DateTimeImmutable('2026-07-04 00:00:00'),
            finishedAt: new \DateTimeImmutable('2026-07-04 00:00:05'),
            providerMessageId: 'provider-abc',
            providerStatus: '550',
            providerResponse: ['code' => 550, 'text' => 'Ящик не існує'],
            errorCode: 'mailbox_unavailable',
            errorMessage: 'boom',
        );
    }

    /**
     * The one that fails on the old code: `Uuid7::fromBytes()` is handed the
     * 36-character string the hydrator produced and answers «Expected 16 bytes,
     * got 36».
     */
    #[Test]
    public function an_attempt_survives_the_conversions_the_orm_performs_around_it(): void
    {
        $attempt = $this->attempt(Uuid7::generate());

        $mapper = new MailAttemptMapper();
        $resource = $mapper->toSourceModel($attempt);

        self::assertInstanceOf(MailAttemptResource::class, $resource);

        $caster = new TypeCaster();
        $column = new ColumnDefinition(
            name: 'mail_message_id',
            type: MySqlType::Binary,
            phpType: 'string',
            length: 16,
        );

        $stored = $caster->castToDb($resource->mail_message_id, $column);

        self::assertSame(16, strlen((string) $stored), 'the column is BINARY(16) and must receive 16 bytes');

        $hydrated = new MailAttemptResource(
            id: $resource->id,
            tenant_id: $resource->tenant_id,
            mail_message_id: (string) $caster->castFromDb($stored, $column),
            attempt_no: $resource->attempt_no,
            driver: $resource->driver,
            status: $resource->status,
            started_at: $resource->started_at,
            finished_at: $resource->finished_at,
            provider_message_id: $resource->provider_message_id,
            provider_status: $resource->provider_status,
            provider_response_json: $resource->provider_response_json,
            error_code: $resource->error_code,
            error_message: $resource->error_message,
        );

        self::assertEquals($attempt, $mapper->toDomain($hydrated));
    }

    /** Every field, so a name dropped from the constructor call is caught too. */
    #[Test]
    public function an_attempt_round_trips_with_every_field_set(): void
    {
        $attempt = $this->attempt(Uuid7::generate());

        $mapper = new MailAttemptMapper();

        self::assertEquals($attempt, $mapper->toDomain($mapper->toSourceModel($attempt)));
    }

    /** The provider response is a real mapper conversion and stays one. */
    #[Test]
    public function an_empty_provider_response_is_stored_as_nothing_rather_than_as_an_empty_object(): void
    {
        $attempt = new MailAttempt(
            id: Uuid7::generate(),
            tenantId: null,
            mailMessageId: Uuid7::generate(),
            attemptNo: 1,
            driver: 'smtp',
            status: 'sent',
            providerResponse: [],
        );

        $mapper = new MailAttemptMapper();
        $resource = $mapper->toSourceModel($attempt);

        self::assertNull($resource->provider_response_json);
        self::assertSame([], $mapper->toDomain($resource)->getProviderResponse());
    }
}
