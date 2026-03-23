<?php

declare(strict_types=1);

namespace Semitexa\Mail\Contract;

use Semitexa\Mail\Value\MailerConfig;

interface MailerConfigResolverInterface
{
    public function resolve(?string $tenantId = null, ?string $mailerKey = null): MailerConfig;
}
