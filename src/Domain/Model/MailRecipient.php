<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

final readonly class MailRecipient
{
    public function __construct(
        public string $email,
        public ?string $name = null,
    ) {
        // The address goes verbatim into the To/Cc/From headers AND into the
        // SMTP MAIL FROM / RCPT TO commands: a CR or LF there injects headers
        // or whole SMTP commands, and angle brackets break out of <...>.
        if (preg_match('/[\x00-\x1F\x7F<>]/', $email) === 1) {
            throw new \InvalidArgumentException('Mail address contains a control character or angle bracket.');
        }
    }

    public function formatted(): string
    {
        if ($this->name !== null && $this->name !== '') {
            $display = self::displayName($this->name);
            $address = "<{$this->email}>";
            if (str_starts_with($display, "\r\n")) {
                // Encoded: it starts on its own folded line, and so does the
                // address. The recipient list joins entries with ", " without
                // folding, so whatever follows must never share a line with
                // an encoded-word (RFC 2047 6.2 caps that line at 76).
                return $display . "\r\n " . $address;
            }

            return $display . ' ' . $address;
        }
        return $this->email;
    }

    /**
     * A name outside printable ASCII — including CR/LF, which would otherwise
     * start a new header line such as `Bcc:` — is RFC 2047 encoded, so it can
     * only ever be text inside this one header.
     */
    private static function displayName(string $name): string
    {
        if (preg_match('/[^\x20-\x7E]/', $name) === 1) {
            return EncodedWord::encode($name, EncodedWord::LINE_LIMIT);
        }

        return '"' . addcslashes($name, '"\\') . '"';
    }
}
