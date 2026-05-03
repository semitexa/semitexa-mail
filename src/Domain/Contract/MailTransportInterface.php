<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailerConfig;
use Semitexa\Mail\Domain\Model\MailTransportResult;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;

interface MailTransportInterface
{
    public function key(): string;

    public function deliver(PreparedMailMessage $message, MailerConfig $config): MailTransportResult;
}
