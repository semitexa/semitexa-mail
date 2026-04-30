<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Enum;

enum MailErrorCode: string
{
    case ConfigError = 'config_error';
    case AuthError = 'auth_error';
    case NetworkError = 'network_error';
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';
    case Provider4xx = 'provider_4xx';
    case Provider5xx = 'provider_5xx';
    case InvalidRecipient = 'invalid_recipient';
    case AttachmentMissing = 'attachment_missing';
    case RenderFailed = 'render_failed';
    case QueueUnavailable = 'queue_unavailable';
    case UnknownTransportError = 'unknown_transport_error';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::NetworkError,
            self::Timeout,
            self::RateLimited,
            self::Provider5xx => true,
            default => false,
        };
    }
}
