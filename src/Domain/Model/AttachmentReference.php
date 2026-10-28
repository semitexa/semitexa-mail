<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

final readonly class AttachmentReference
{
    public const int MAX_INLINE_SIZE = 262144; // 256 KB

    public function __construct(
        public string $filename,
        public string $mimeType = 'application/octet-stream',
        public string $disposition = 'attachment',
        public ?string $contentId = null,
        public ?string $storagePath = null,
        public ?string $inlineContents = null,
        public ?int $sizeHint = null,
    ) {}

    /**
     * An attachment as mail_messages stores it (MailService serializes it);
     * the worker and a re-send read it back the same way.
     *
     * @param array<string, mixed> $data
     */
    public static function fromStored(array $data): self
    {
        // `=== false`, not `?:`: an attachment whose contents are "0" or empty
        // decoded to a falsy string, became null, and was "missing".
        $decoded = isset($data['inlineContentsBase64']) && is_string($data['inlineContentsBase64'])
            ? base64_decode($data['inlineContentsBase64'], true)
            : false;
        $inline = $decoded === false ? null : $decoded;

        return new self(
            filename:       is_string($data['filename'] ?? null) ? $data['filename'] : 'attachment',
            mimeType:       is_string($data['mimeType'] ?? null) ? $data['mimeType'] : 'application/octet-stream',
            disposition:    is_string($data['disposition'] ?? null) ? $data['disposition'] : 'attachment',
            contentId:      is_string($data['contentId'] ?? null) ? $data['contentId'] : null,
            storagePath:    is_string($data['storagePath'] ?? null) ? $data['storagePath'] : null,
            inlineContents: $inline,
            sizeHint:       is_int($data['sizeHint'] ?? null) ? $data['sizeHint'] : null,
        );
    }

    public function isStorageBacked(): bool
    {
        return $this->storagePath !== null;
    }

    public function isInline(): bool
    {
        return $this->inlineContents !== null;
    }
}
