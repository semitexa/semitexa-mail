<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Enum;

enum MailDispatchStatus: string
{
    case Sent = 'sent';
    case Queued = 'queued';
    case Failed = 'failed';
    case EnqueueFailed = 'enqueue_failed';
}
