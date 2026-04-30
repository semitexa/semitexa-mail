<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

final class PreparedMailMessage
{
    public string $messageId = '';

    public MailRecipient $from;

    public ?MailRecipient $replyTo = null;

    /** @var list<MailRecipient> */
    public array $to = [];

    /** @var list<MailRecipient> */
    public array $cc = [];

    /** @var list<MailRecipient> */
    public array $bcc = [];

    public string $subject = '';
    public ?string $htmlBody = null;
    public ?string $textBody = null;

    /** @var array<string, string> */
    public array $headers = [];

    /** @var list<ResolvedAttachment> */
    public array $attachments = [];

    public function __construct()
    {
        $this->from = new MailRecipient('');
    }
}
