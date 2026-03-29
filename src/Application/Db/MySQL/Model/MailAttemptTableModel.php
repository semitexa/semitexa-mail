<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'mail_attempts')]
final readonly class MailAttemptTableModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenant_id,
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $mail_message_id,
        #[Column(type: MySqlType::SmallInt)]
        public int $attempt_no,
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $driver,
        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,
        #[Column(type: MySqlType::Datetime)]
        public ?\DateTimeImmutable $started_at,
        #[Column(type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $finished_at,
        #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $provider_message_id,
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $provider_status,
        #[Column(type: MySqlType::Json, nullable: true)]
        public ?string $provider_response_json,
        #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $error_code,
        #[Column(type: MySqlType::Text, nullable: true)]
        public ?string $error_message,
    ) {}
}
