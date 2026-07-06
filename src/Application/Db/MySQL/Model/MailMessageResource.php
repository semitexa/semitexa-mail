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
 * One queued/sent mail message (`mail_messages`).
 *
 * `final readonly` per the ORM contract: mutations rebuild the row via
 * {@see copyWith()}; the write engine generates the UUID primary key on
 * insert and RETURNS the persisted instance (repositories pass it back).
 *
 * Tenant-scoped: reads must declare their posture — forTenant(...) on a
 * tenant surface, or the repository's system() view for infrastructure
 * (the worker addresses messages by their own unique id). `tenant_id` is
 * nullable: system mail (no tenant) is a legal row.
 */
#[FromTable(name: 'mail_messages')]
#[Index(columns: ['tenant_id', 'idempotency_key'], unique: true, name: 'uniq_mail_messages_tenant_idempotency')]
#[Index(columns: ['status'], name: 'idx_mail_messages_status')]
#[Index(columns: ['tenant_id'], name: 'idx_mail_messages_tenant')]
#[TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final readonly class MailMessageResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id = '',

        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id = null,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status = 'pending',

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $driver = 'smtp',

        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $template_handle = null,

        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $from_email = '',

        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $from_name = null,

        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $reply_to = null,

        #[Column(type: MySqlType::Json)]
        public string $to_json = '[]',

        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $cc_json = null,

        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $bcc_json = null,

        #[Column(type: MySqlType::Varchar, length: 998)]
        public string $subject = '',

        #[Column(type: MySqlType::MediumText, nullable: true)]
        public ?string $html_body = null,

        #[Column(type: MySqlType::MediumText, nullable: true)]
        public ?string $text_body = null,

        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $headers_json = null,

        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $tags_json = null,

        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $metadata_json = null,

        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $attachments_json = null,

        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $idempotency_key = null,

        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $provider_message_id = null,

        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $error_code = null,

        #[Column(type: MySqlType::Text, nullable: true)]
        public ?string $error_message = null,

        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $last_attempt_at = null,

        #[Column(type: MySqlType::Datetime)]
        public ?\DateTimeImmutable $created_at = null,

        #[Column(type: MySqlType::Datetime)]
        public ?\DateTimeImmutable $updated_at = null,
    ) {
    }

    /**
     * Rebuild the row with the given property overrides (property name =>
     * value). `updated_at` refreshes automatically unless overridden.
     *
     * @param array<string, mixed> $overrides
     */
    public function copyWith(array $overrides): self
    {
        $overrides += ['updated_at' => new \DateTimeImmutable()];

        return new self(...array_merge(get_object_vars($this), $overrides));
    }
}
