<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'mail_messages')]
#[Index(columns: ['tenant_id', 'idempotency_key'], unique: true, name: 'uniq_mail_messages_tenant_idempotency')]
#[Index(columns: ['status'], name: 'idx_mail_messages_status')]
#[Index(columns: ['tenant_id'], name: 'idx_mail_messages_tenant')]
class MailMessageResource
{
    use HasUuidV7;
    use HasTimestamps;
    use HasColumnReferences;
    use HasRelationReferences;

    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $tenant_id = null;

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $status = 'pending';

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $driver = 'smtp';

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $template_handle = null;

    #[Column(type: MySqlType::Varchar, length: 255)]
    public string $from_email = '';

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $from_name = null;

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $reply_to = null;

    #[Column(type: MySqlType::Json)]
    public string $to_json = '[]';

    #[Column(type: MySqlType::Json, nullable: true)]
    public ?string $cc_json = null;

    #[Column(type: MySqlType::Json, nullable: true)]
    public ?string $bcc_json = null;

    #[Column(type: MySqlType::Varchar, length: 998)]
    public string $subject = '';

    #[Column(type: MySqlType::MediumText, nullable: true)]
    public ?string $html_body = null;

    #[Column(type: MySqlType::MediumText, nullable: true)]
    public ?string $text_body = null;

    #[Column(type: MySqlType::Json, nullable: true)]
    public ?string $headers_json = null;

    #[Column(type: MySqlType::Json, nullable: true)]
    public ?string $tags_json = null;

    #[Column(type: MySqlType::Json, nullable: true)]
    public ?string $metadata_json = null;

    #[Column(type: MySqlType::Json, nullable: true)]
    public ?string $attachments_json = null;

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $idempotency_key = null;

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $provider_message_id = null;

    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $error_code = null;

    #[Column(type: MySqlType::Text, nullable: true)]
    public ?string $error_message = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $last_attempt_at = null;
}
