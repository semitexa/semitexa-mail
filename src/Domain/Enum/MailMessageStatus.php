<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Enum;

enum MailMessageStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Deferred = 'deferred';
    case Failed = 'failed';
    case EnqueueFailed = 'enqueue_failed';
}
