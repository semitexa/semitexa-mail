<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

use Semitexa\Mail\Domain\Enum\MailSendMode;

final readonly class MailSendOptions
{
    public function __construct(
        public MailSendMode $mode = MailSendMode::Queued,
    ) {}
}
