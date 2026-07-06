<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * One delivery attempt of a mail message (`mail_attempts`, insert-only).
 *
 * `final readonly` per the ORM contract; the write engine generates the UUID
 * primary key on insert and returns the persisted instance. Tenant-scoped
 * like {@see MailMessageResource}; the worker's reads go through the
 * repository's system() view (it addresses attempts by message id).
 */
#[FromTable(name: 'mail_attempts')]
#[Index(columns: ['mail_message_id'], name: 'idx_mail_attempts_message')]
#[TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final readonly class MailAttemptResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id = '',

        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id = null,

        #[Column(type: MySqlType::Binary, length: 16)]
        public string $mail_message_id = '',

        #[Column(type: MySqlType::SmallInt)]
        public int $attempt_no = 1,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $driver = 'smtp',

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status = 'failed',

        #[Column(type: MySqlType::Datetime)]
        public ?\DateTimeImmutable $started_at = null,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $finished_at = null,

        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $provider_message_id = null,

        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $provider_status = null,

        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $provider_response_json = null,

        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $error_code = null,

        #[Column(type: MySqlType::Text, nullable: true)]
        public ?string $error_message = null,
    ) {
    }
}
