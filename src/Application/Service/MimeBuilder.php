<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Mail\Domain\Model\EncodedWord;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;
use Semitexa\Mail\Domain\Model\ResolvedAttachment;

final class MimeBuilder
{
    /** RFC 5322 2.1.1: a line holds at most 998 characters before its CRLF. */
    private const int MAX_LINE = 998;

    /**
     * The percent-encoded file name, at most: beside the quoted ASCII form
     * (160 with every character escaped) and the parameter text, the
     * Content-Disposition line stays under MAX_LINE with room for a long type.
     */
    private const int MAX_ENCODED_FILENAME = 600;

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

        $lines[] = 'Subject: ' . $this->encodeHeader($message->subject, strlen('Subject: '));
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Date: ' . gmdate('D, d M Y H:i:s +0000');
        // An id with no domain is not a valid Message-ID (RFC 5322 id-right).
        $domain = (string) substr(strrchr($message->from->email, '@') ?: '@localhost', 1);
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $domain) === 1) {
            $domain = (string) (idn_to_ascii($domain) ?: $domain);
        }
        $lines[] = 'Message-ID: <' . $message->messageId . '@' . (SmtpMailTransport::isDomain($domain) ? $domain : 'localhost') . '>';

        foreach ($message->headers as $name => $value) {
            // A field name is printable ASCII without a colon (RFC 5322 2.2); the
            // value is encoded like the subject, so a CR/LF in it cannot start
            // a header line of its own.
            if (preg_match('/^[\x21-\x39\x3B-\x7E]+\z/', (string) $name) !== 1) {
                throw new \InvalidArgumentException(sprintf('Invalid mail header name "%s".', addcslashes((string) $name, "\0..\37")));
            }
            $lines[] = $name . ': ' . $this->encodeHeader((string) $value, strlen($name) + 2);
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
        // File name, type and Content-ID went into the headers as given: a
        // name with a quote and a line break added headers of its own.
        $token = '[A-Za-z0-9!#$%&\'*+.^_`|~-]+';
        // [ \t], not \s, and D: \s took a line break, and $ a trailing one.
        $mimeType = preg_match("@^{$token}/{$token}([ \t]*;[ \t]*{$token}=({$token}|\"[^\"\\r\\n]*\"))*$@D", $attachment->mimeType) === 1
            ? $attachment->mimeType
            : 'application/octet-stream';
        // Only what could end the header or the angle brackets goes: the
        // HTML's cid: reference must still match.
        $contentId = $attachment->contentId !== null ? preg_replace('/[\x00-\x20\x7F<>]/', '', $attachment->contentId) : null;

        $out = "Content-Type: {$mimeType}; " . self::fileParameter('name', $attachment->filename) . "\r\n";
        $out .= "Content-Transfer-Encoding: base64\r\n";

        if ($attachment->disposition === 'inline' && $contentId !== null && $contentId !== '') {
            $out .= 'Content-Disposition: inline; ' . self::fileParameter('filename', $attachment->filename) . "\r\n";
            $out .= "Content-ID: <{$contentId}>\r\n";
        } else {
            $out .= 'Content-Disposition: attachment; ' . self::fileParameter('filename', $attachment->filename) . "\r\n";
        }

        $out .= "\r\n";
        $out .= chunk_split(base64_encode($attachment->contents), 76, "\r\n");

        return $out;
    }

    /**
     * A quoted file-name parameter: control characters dropped, quote and
     * backslash escaped, and a non-ASCII name also given RFC 2231-encoded.
     */
    private static function fileParameter(string $param, string $filename): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
        // Long enough for any real name, short enough that both forms stay
        // well inside the 998-octet line limit (no RFC 2231 continuations).
        // Bounded in encoded bytes too: eighty emoji are 960 of them
        // percent-encoded, and the header line went past 998.
        if (mb_strlen($clean, 'UTF-8') > 80 || strlen(rawurlencode($clean)) > self::MAX_ENCODED_FILENAME) {
            $extension = pathinfo($clean, PATHINFO_EXTENSION);
            $keep = $extension !== '' && mb_strlen($extension, 'UTF-8') <= 10 ? '.' . $extension : '';
            $length = 80 - mb_strlen($keep, 'UTF-8');
            do {
                $cut = mb_substr($clean, 0, $length--, 'UTF-8') . $keep;
            } while ($length > 0 && strlen(rawurlencode($cut)) > self::MAX_ENCODED_FILENAME);
            $clean = $cut;
        }
        // One underscore per character, not per byte, where it is UTF-8.
        $ascii = (string) (preg_replace('/[^\x20-\x7E]/u', '_', $clean) ?? preg_replace('/[^\x20-\x7E]/', '_', $clean));
        $quoted = $param . '="' . addcslashes($ascii, '"\\') . '"';

        return $ascii === $clean
            ? $quoted
            : $quoted . '; ' . $param . "*=UTF-8''" . rawurlencode($clean);
    }

    /**
     * @param list<\Semitexa\Mail\Domain\Model\MailRecipient> $recipients
     */
    private function formatRecipientList(array $recipients): string
    {
        $list = implode(', ', array_map(fn($r) => $r->formatted(), $recipients));
        // Folded after each comma once it is long: sixty named recipients made
        // one 2 300-character To: line, past the 998 a line may hold.
        if (strlen($list) <= 72) {
            return $list;
        }

        return implode(",\r\n ", array_map(fn($r) => $r->formatted(), $recipients));
    }

    /** @param int $lineUsed length of the "Name: " the value follows */
    private function encodeHeader(string $value, int $lineUsed): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return EncodedWord::encode($value, $lineUsed);
        }
        // Plain ASCII was never folded: a 2 000-character subject was one
        // line, past the 998 a line may hold. Folded at spaces, which
        // unfolding gives back.
        if ($lineUsed + strlen($value) > 78) {
            $folded = wordwrap($value, 76, "\r\n ", false);
            // A run with no space to fold at (a 1 000-character token) stayed
            // one line past 998: encoded-words split anywhere.
            $lines = explode("\r\n", $folded);
            $lines[0] = str_repeat(' ', $lineUsed) . $lines[0];
            if (max(array_map('strlen', $lines)) > self::MAX_LINE) {
                return EncodedWord::encode($value, $lineUsed);
            }

            return $folded;
        }
        return $value;
    }

    private function generateBoundary(string $prefix): string
    {
        return "----=_{$prefix}_" . bin2hex(random_bytes(16));
    }
}
