<?php

declare(strict_types=1);

namespace Semitexa\Mail\Contract;

use Semitexa\Mail\Value\MailerConfig;
use Semitexa\Mail\Value\MailTransportResult;
use Semitexa\Mail\Value\PreparedMailMessage;

interface MailTransportInterface
{
    public function key(): string;

    public function deliver(PreparedMailMessage $message, MailerConfig $config): MailTransportResult;
}
