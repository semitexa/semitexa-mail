<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\Mail\Domain\Contract\MailerConfigResolverInterface;
use Semitexa\Mail\Domain\Model\MailerConfig;

#[SatisfiesServiceContract(of: MailerConfigResolverInterface::class)]
final class EnvMailerConfigResolver implements MailerConfigResolverInterface
{
    /**
     * Each key read with Environment::getEnvValue() by name: the process env
     * first, then the project's .env files, and the docs truth index sees the
     * keys (getenv() hid them, so the docs linter could not check them).
     */
    public function resolve(?string $tenantId = null, ?string $mailerKey = null): MailerConfig
    {
        $orNull = static fn (?string $value): ?string => $value !== null && $value !== '' ? $value : null;

        return new MailerConfig(
            driver:         (string) Environment::getEnvValue('MAIL_DRIVER', 'smtp'),
            host:           (string) Environment::getEnvValue('MAIL_HOST', '127.0.0.1'),
            port:           (int) Environment::getEnvValue('MAIL_PORT', '1025'),
            username:       $orNull(Environment::getEnvValue('MAIL_USERNAME')),
            password:       $orNull(Environment::getEnvValue('MAIL_PASSWORD')),
            encryption:     MailerConfig::normalizeEncryption($orNull(Environment::getEnvValue('MAIL_ENCRYPTION'))),
            fromEmail:      (string) Environment::getEnvValue('MAIL_FROM_EMAIL', 'noreply@localhost'),
            fromName:       $orNull(Environment::getEnvValue('MAIL_FROM_NAME')),
            replyTo:        $orNull(Environment::getEnvValue('MAIL_REPLY_TO')),
            timeoutSeconds: (int) Environment::getEnvValue('MAIL_TIMEOUT', '30'),
            queue:          (string) Environment::getEnvValue('MAIL_QUEUE', 'mail'),
            verifyPeer:     !in_array(strtolower((string) Environment::getEnvValue('MAIL_VERIFY_PEER', 'true')), ['0', 'false', 'no', 'off'], true),
        );
    }
}
