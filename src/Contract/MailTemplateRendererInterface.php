<?php

declare(strict_types=1);

namespace Semitexa\Mail\Contract;

use Semitexa\Mail\Value\MailEnvelope;

interface MailTemplateRendererInterface
{
    /**
     * Renders subject, HTML body, and text body from a template handle.
     *
     * @return array{subject: string, htmlBody: ?string, textBody: ?string}
     */
    public function render(string $templateHandle, array $variables, ?string $locale = null): array;
}
