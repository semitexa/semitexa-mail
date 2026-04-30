<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Contract;

use Semitexa\Mail\Domain\Model\MailerConfig;

interface MailerConfigResolverInterface
{
    public function resolve(?string $tenantId = null, ?string $mailerKey = null): MailerConfig;
}
