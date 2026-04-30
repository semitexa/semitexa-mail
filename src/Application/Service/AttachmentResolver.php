<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Mail\Domain\Model\AttachmentReference;
use Semitexa\Mail\Domain\Model\ResolvedAttachment;
use Semitexa\Storage\Domain\Contract\StorageDriverInterface;

#[AsService]
final class AttachmentResolver
{
    #[InjectAsReadonly]
    protected StorageDriverInterface $storage;

    /**
     * @param list<AttachmentReference> $references
     * @return list<ResolvedAttachment>
     * @throws \RuntimeException on missing or oversized attachment
     */
    public function resolve(array $references): array
    {
        $resolved = [];

        foreach ($references as $ref) {
            $resolved[] = $this->resolveOne($ref);
        }

        return $resolved;
    }

    private function resolveOne(AttachmentReference $ref): ResolvedAttachment
    {
        if ($ref->isInline()) {
            if (strlen($ref->inlineContents) > AttachmentReference::MAX_INLINE_SIZE) {
                throw new \RuntimeException(
                    "Inline attachment '{$ref->filename}' exceeds the 256 KB size limit. "
                    . 'Use a storage-backed reference for large files.',
                );
            }

            return new ResolvedAttachment(
                filename:    $ref->filename,
                mimeType:    $ref->mimeType,
                contents:    $ref->inlineContents,
                disposition: $ref->disposition,
                contentId:   $ref->contentId,
            );
        }

        if ($ref->isStorageBacked()) {
            $contents = $this->storage->get($ref->storagePath);

            if ($contents === null) {
                throw new \RuntimeException(
                    "Storage attachment '{$ref->storagePath}' not found (filename: '{$ref->filename}').",
                );
            }

            return new ResolvedAttachment(
                filename:    $ref->filename,
                mimeType:    $ref->mimeType,
                contents:    $contents,
                disposition: $ref->disposition,
                contentId:   $ref->contentId,
            );
        }

        throw new \RuntimeException(
            "Attachment '{$ref->filename}' has neither inline contents nor a storage path.",
        );
    }
}
