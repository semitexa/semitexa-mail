<?php

declare(strict_types=1);

namespace Semitexa\Mail\Exception;

final class SmtpException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $smtpCode,
        public readonly string $smtpResponse,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $smtpCode, $previous);
    }
}
