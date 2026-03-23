<?php

declare(strict_types=1);

namespace Semitexa\Mail\Value;

final readonly class MailSendOptions
{
    public function __construct(
        public MailSendMode $mode = MailSendMode::Queued,
    ) {}
}
