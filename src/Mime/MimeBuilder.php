<?php

declare(strict_types=1);

namespace Semitexa\Mail\Mime;

use Semitexa\Mail\Value\PreparedMailMessage;
use Semitexa\Mail\Value\ResolvedAttachment;

final class MimeBuilder
{
    /**
     * Builds the full MIME message including headers and body.
     *
     * @return array{headers: string, body: string}
     */
    public function build(PreparedMailMessage $message): array
    {
        $headers = $this->buildHeaders($message);
        $body = $this->buildBody($message);

        return ['headers' => $headers, 'body' => $body];
    }

    private function buildHeaders(PreparedMailMessage $message): string
    {
        $lines = [];
        $lines[] = 'From: ' . $message->from->formatted();
        $lines[] = 'To: ' . $this->formatRecipientList($message->to);

        if ($message->cc !== []) {
            $lines[] = 'Cc: ' . $this->formatRecipientList($message->cc);
        }

        if ($message->replyTo !== null) {
            $lines[] = 'Reply-To: ' . $message->replyTo->formatted();
        }

        $lines[] = 'Subject: ' . $this->encodeHeader($message->subject);
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Date: ' . gmdate('D, d M Y H:i:s +0000');
        $lines[] = 'Message-ID: <' . $message->messageId . '>';

        foreach ($message->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines);
    }

    private function buildBody(PreparedMailMessage $message): string
    {
        $hasHtml = $message->htmlBody !== null && $message->htmlBody !== '';
        $hasText = $message->textBody !== null && $message->textBody !== '';
        $hasAttachments = $message->attachments !== [];

        if ($hasAttachments) {
            return $this->buildMixed($message, $hasHtml, $hasText);
        }

        if ($hasHtml && $hasText) {
            return $this->buildAlternative($message->htmlBody, $message->textBody);
        }

        if ($hasHtml) {
            return "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
                . quoted_printable_encode($message->htmlBody);
        }

        return "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($message->textBody ?? '');
    }

    private function buildAlternative(string $html, string $text): string
    {
        $boundary = $this->generateBoundary('alt');
        $out = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";

        $out .= "--{$boundary}\r\n";
        $out .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $out .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $out .= quoted_printable_encode($text) . "\r\n";

        $out .= "--{$boundary}\r\n";
        $out .= "Content-Type: text/html; charset=UTF-8\r\n";
        $out .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $out .= quoted_printable_encode($html) . "\r\n";

        $out .= "--{$boundary}--\r\n";

        return $out;
    }

    private function buildMixed(PreparedMailMessage $message, bool $hasHtml, bool $hasText): string
    {
        $mixedBoundary = $this->generateBoundary('mixed');
        $out = "Content-Type: multipart/mixed; boundary=\"{$mixedBoundary}\"\r\n\r\n";

        // Body part
        $out .= "--{$mixedBoundary}\r\n";
        if ($hasHtml && $hasText) {
            $out .= $this->buildAlternative($message->htmlBody, $message->textBody);
        } elseif ($hasHtml) {
            $out .= "Content-Type: text/html; charset=UTF-8\r\n";
            $out .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $out .= quoted_printable_encode($message->htmlBody) . "\r\n";
        } else {
            $out .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $out .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
            $out .= quoted_printable_encode($message->textBody ?? '') . "\r\n";
        }

        // Attachments
        foreach ($message->attachments as $attachment) {
            $out .= "--{$mixedBoundary}\r\n";
            $out .= $this->buildAttachmentPart($attachment);
        }

        $out .= "--{$mixedBoundary}--\r\n";

        return $out;
    }

    private function buildAttachmentPart(ResolvedAttachment $attachment): string
    {
        $out = "Content-Type: {$attachment->mimeType}; name=\"{$attachment->filename}\"\r\n";
        $out .= "Content-Transfer-Encoding: base64\r\n";

        if ($attachment->disposition === 'inline' && $attachment->contentId !== null) {
            $out .= "Content-Disposition: inline; filename=\"{$attachment->filename}\"\r\n";
            $out .= "Content-ID: <{$attachment->contentId}>\r\n";
        } else {
            $out .= "Content-Disposition: attachment; filename=\"{$attachment->filename}\"\r\n";
        }

        $out .= "\r\n";
        $out .= chunk_split(base64_encode($attachment->contents), 76, "\r\n");

        return $out;
    }

    /**
     * @param list<\Semitexa\Mail\Value\MailRecipient> $recipients
     */
    private function formatRecipientList(array $recipients): string
    {
        return implode(', ', array_map(fn($r) => $r->formatted(), $recipients));
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private function generateBoundary(string $prefix): string
    {
        return "----=_{$prefix}_" . bin2hex(random_bytes(16));
    }
}
