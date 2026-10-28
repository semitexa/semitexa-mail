<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

use Semitexa\Mail\Domain\Enum\MailTransportStatus;

final readonly class MailTransportResult
{
    public function __construct(
        public MailTransportStatus $status,
        public ?string $providerMessageId = null,
        public ?string $providerStatus = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        /** @var array<string, mixed> */
        public array $providerResponse = [],
    ) {}

    /**
     * The warning of an accepted mail, as a log line may carry it. A partial
     * refusal names every refused address and the server's reply to it: those
     * stay on the mail row, and the log says how many. Another transport's
     * warning is said as it is.
     */
    public function warningForLog(): ?string
    {
        if ($this->errorMessage === null) {
            return null;
        }
        $refused = $this->providerResponse['refused_recipients'] ?? null;

        return is_array($refused)
            ? sprintf('%d recipient(s) refused; who and why is on the mail row.', count($refused))
            : $this->errorMessage;
    }
}
