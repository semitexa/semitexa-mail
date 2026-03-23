<?php

declare(strict_types=1);

namespace Semitexa\Mail\Contract;

use Semitexa\Mail\Value\MailDispatchResult;
use Semitexa\Mail\Value\MailEnvelope;
use Semitexa\Mail\Value\MailSendOptions;

interface MailServiceInterface
{
    public function send(MailEnvelope $envelope, ?MailSendOptions $options = null): MailDispatchResult;
}
