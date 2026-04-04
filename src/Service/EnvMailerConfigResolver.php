<?php

declare(strict_types=1);

namespace Semitexa\Mail\Service;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Mail\Contract\MailerConfigResolverInterface;
use Semitexa\Mail\Value\MailerConfig;

#[SatisfiesServiceContract(of: MailerConfigResolverInterface::class)]
final class EnvMailerConfigResolver implements MailerConfigResolverInterface
{
    public function resolve(?string $tenantId = null, ?string $mailerKey = null): MailerConfig
    {
        return new MailerConfig(
            driver:         $this->env('MAIL_DRIVER', 'smtp'),
            host:           $this->env('MAIL_HOST', '127.0.0.1'),
            port:           (int) $this->env('MAIL_PORT', '1025'),
            username:       $this->envOrNull('MAIL_USERNAME'),
            password:       $this->envOrNull('MAIL_PASSWORD'),
            encryption:     $this->envOrNull('MAIL_ENCRYPTION'),
            fromEmail:      $this->env('MAIL_FROM_EMAIL', 'noreply@localhost'),
            fromName:       $this->envOrNull('MAIL_FROM_NAME'),
            replyTo:        $this->envOrNull('MAIL_REPLY_TO'),
            timeoutSeconds: (int) $this->env('MAIL_TIMEOUT', '30'),
            queue:          $this->env('MAIL_QUEUE', 'mail'),
        );
    }

    private function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value !== false ? $value : $default;
    }

    private function envOrNull(string $key): ?string
    {
        $value = getenv($key);

        return ($value !== false && $value !== '') ? $value : null;
    }
}
