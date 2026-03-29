<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'mail_messages')]
final readonly class MailMessageTableModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id,
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $driver,
        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $template_handle,
        #[Column(type: MySqlType::Varchar, length: 255)]
        public string $from_email,
        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $from_name,
        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $reply_to,
        #[Column(type: MySqlType::Json)]
        public string $to_json,
        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $cc_json,
        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $bcc_json,
        #[Column(type: MySqlType::Varchar, length: 998)]
        public string $subject,
        #[Column(type: MySqlType::MediumText, nullable: true)]
        public ?string $html_body,
        #[Column(type: MySqlType::MediumText, nullable: true)]
        public ?string $text_body,
        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $headers_json,
        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $tags_json,
        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $metadata_json,
        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $attachments_json,
        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $idempotency_key,
        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $provider_message_id,
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $error_code,
        #[Column(type: MySqlType::Text, nullable: true)]
        public ?string $error_message,
        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $last_attempt_at,
        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $created_at,
        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updated_at,
    ) {}
}
