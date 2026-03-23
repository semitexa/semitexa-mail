<?php

declare(strict_types=1);

namespace Semitexa\Mail\Value;

final readonly class ResolvedAttachment
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $contents,
        public string $disposition = 'attachment',
        public ?string $contentId = null,
    ) {}
}
