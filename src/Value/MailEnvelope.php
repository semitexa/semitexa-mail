<?php

declare(strict_types=1);

namespace Semitexa\Mail\Value;

final class MailEnvelope
{
    public string $mailKey = '';
    public ?string $tenantId = null;
    public ?string $locale = null;
    public ?string $mailerKey = null;
    public ?string $idempotencyKey = null;

    /** @var list<MailRecipient> */
    public array $to = [];

    /** @var list<MailRecipient> */
    public array $cc = [];

    /** @var list<MailRecipient> */
    public array $bcc = [];

    public ?MailRecipient $from = null;
    public ?MailRecipient $replyTo = null;

    public ?string $templateHandle = null;
    public ?string $subject = null;
    public ?string $htmlBody = null;
    public ?string $textBody = null;

    /** @var array<string, mixed> */
    public array $variables = [];

    /** @var list<AttachmentReference> */
    public array $attachments = [];

    /** @var array<string, scalar|null> */
    public array $metadata = [];

    /** @var array<string, string> */
    public array $headers = [];

    /** @var list<string> */
    public array $tags = [];
}
