<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'mail_attempts')]
#[Index(columns: ['mail_message_id'], name: 'idx_mail_attempts_message')]
class MailAttemptResource
{
    use HasUuidV7;
    use HasColumnReferences;
    use HasRelationReferences;

    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $tenant_id = null;

    #[Column(type: MySqlType::Binary, length: 16)]
    public string $mail_message_id = '';

    #[Column(type: MySqlType::SmallInt)]
    public int $attempt_no = 1;

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $driver = 'smtp';

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $status = 'failed';

    #[Column(type: MySqlType::Datetime)]
    public ?\DateTimeImmutable $started_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $finished_at = null;

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $provider_message_id = null;

    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $provider_status = null;

    #[Column(type: MySqlType::Json, nullable: true)]
    public ?string $provider_response_json = null;

    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $error_code = null;

    #[Column(type: MySqlType::Text, nullable: true)]
    public ?string $error_message = null;
}
