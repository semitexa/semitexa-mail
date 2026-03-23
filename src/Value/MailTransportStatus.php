<?php

declare(strict_types=1);

namespace Semitexa\Mail\Value;

enum MailTransportStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Deferred = 'deferred';
    case Failed = 'failed';
}
