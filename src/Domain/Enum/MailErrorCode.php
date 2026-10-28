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
    /** The mail itself cannot be stored or sent as it is (a subject past 998 characters). */
    case InvalidMessage = 'invalid_message';
    case QueueUnavailable = 'queue_unavailable';
    case UnknownTransportError = 'unknown_transport_error';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::NetworkError,
            self::Timeout,
            self::RateLimited,
            // SMTP replies (the only transport that produces these): a 4xx is
            // temporary (greylisting answers 450/451), a 5xx permanent. It was
            // the other way round, as for an HTTP API.
            self::Provider4xx => true,
            default => false,
        };
    }
}
