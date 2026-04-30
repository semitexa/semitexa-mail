<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Enum;

enum MailSendMode: string
{
    case Sync = 'sync';
    case Queued = 'queued';
}
