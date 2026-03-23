<?php

declare(strict_types=1);

namespace Semitexa\Mail\Transport;

use Semitexa\Mail\Contract\MailTransportInterface;
use Semitexa\Mail\Value\MailerConfig;
use Semitexa\Mail\Value\MailTransportResult;
use Semitexa\Mail\Value\MailTransportStatus;
use Semitexa\Mail\Value\PreparedMailMessage;

final class NullMailTransport implements MailTransportInterface
{
    public function key(): string
    {
        return 'null';
    }

    public function deliver(PreparedMailMessage $message, MailerConfig $config): MailTransportResult
    {
        return new MailTransportResult(
            status: MailTransportStatus::Accepted,
            providerMessageId: $message->messageId,
        );
    }
}
