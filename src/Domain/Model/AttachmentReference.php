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

    public function isStorageBacked(): bool
    {
        return $this->storagePath !== null;
    }

    public function isInline(): bool
    {
        return $this->inlineContents !== null;
    }
}
