<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailDispatchResult;
use Semitexa\Mail\Domain\Model\MailEnvelope;
use Semitexa\Mail\Domain\Model\MailSendOptions;

interface MailServiceInterface
{
    public function send(MailEnvelope $envelope, ?MailSendOptions $options = null): MailDispatchResult;
}
