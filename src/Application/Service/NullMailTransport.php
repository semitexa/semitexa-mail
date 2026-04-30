<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Mail\Domain\Contract\MailTransportInterface;
use Semitexa\Mail\Domain\Model\MailerConfig;
use Semitexa\Mail\Domain\Model\MailTransportResult;
use Semitexa\Mail\Domain\Enum\MailTransportStatus;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;

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
