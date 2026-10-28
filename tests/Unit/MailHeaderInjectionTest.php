<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mail\Application\Service\MimeBuilder;
use Semitexa\Mail\Domain\Model\EncodedWord;
use Semitexa\Mail\Domain\Model\MailRecipient;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;

/**
 * Every recipient name, address and custom header ends up in the header block
 * (and the address in SMTP commands): none of them may start a line of its own.
 */
final class MailHeaderInjectionTest extends TestCase
{
    #[Test]
    public function a_line_break_in_a_recipient_name_cannot_add_a_header(): void
    {
        $message = $this->message();
        $message->to = [new MailRecipient('victim@example.com', "Bob\r\nBcc: attacker@evil.example")];

        $headers = (new MimeBuilder())->build($message)['headers'];

        self::assertDoesNotMatchRegularExpression('/^Bcc:/mi', $headers);
        self::assertStringContainsString('<victim@example.com>', $headers);
    }

    #[Test]
    public function an_attachment_name_type_or_content_id_cannot_add_a_header(): void
    {
        // They went into the part headers as given.
        $message = $this->message();
        $message->attachments = [new \Semitexa\Mail\Domain\Model\ResolvedAttachment(
            filename: "a.pdf\"\r\nBcc: attacker@evil.example\r\nX: \"",
            mimeType: "application/pdf\r\nBcc: attacker@evil.example",
            contents: '%PDF',
            disposition: 'inline',
            contentId: "cid1>\r\nBcc: attacker@evil.example",
        )];

        $body = (new MimeBuilder())->build($message)['body'];

        self::assertDoesNotMatchRegularExpression('/^Bcc:/mi', $body);
        self::assertStringContainsString('Content-Type: application/octet-stream;', $body);
    }

    #[Test]
    public function a_non_ascii_attachment_name_is_also_sent_encoded(): void
    {
        $message = $this->message();
        $message->attachments = [new \Semitexa\Mail\Domain\Model\ResolvedAttachment(filename: 'звіт.pdf', mimeType: 'application/pdf', contents: '%PDF')];

        $body = (new MimeBuilder())->build($message)['body'];

        self::assertStringContainsString("filename*=UTF-8''" . rawurlencode('звіт.pdf'), $body);
        self::assertStringContainsString('filename="____.pdf"', $body, 'one underscore per character');
    }

    #[Test]
    public function a_valid_type_with_parameters_and_an_unusual_content_id_are_kept(): void
    {
        // The HTML's cid: reference must still match the part.
        $message = $this->message();
        $message->attachments = [new \Semitexa\Mail\Domain\Model\ResolvedAttachment(filename: 'logo "v2".png', mimeType: 'text/csv; charset=utf-8', contents: 'x', disposition: 'inline', contentId: 'logo#1/2x')];

        $body = (new MimeBuilder())->build($message)['body'];

        self::assertStringContainsString('Content-Type: text/csv; charset=utf-8;', $body);
        self::assertStringContainsString('Content-ID: <logo#1/2x>', $body);
        self::assertStringContainsString('filename="logo \\"v2\\".png"', $body, 'a quote is escaped, not dropped');
    }

    #[Test]
    public function a_very_long_non_ascii_name_stays_inside_the_line_limit(): void
    {
        $message = $this->message();
        $message->attachments = [new \Semitexa\Mail\Domain\Model\ResolvedAttachment(filename: str_repeat('звіт', 60) . '.pdf', mimeType: 'application/pdf', contents: 'x')];

        $body = (new MimeBuilder())->build($message)['body'];

        foreach (explode("\r\n", $body) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }
        self::assertStringContainsString('.pdf"', $body, 'the extension is kept');
    }

    /** Eighty emoji were 960 bytes percent-encoded: the Content-Disposition line went past 998. */
    #[Test]
    public function a_name_of_eighty_emoji_stays_inside_the_line_limit(): void
    {
        $message = $this->message();
        $message->attachments = [new \Semitexa\Mail\Domain\Model\ResolvedAttachment(filename: str_repeat("\u{1F600}", 80) . '.pdf', mimeType: 'application/pdf', contents: 'x')];

        $body = (new MimeBuilder())->build($message)['body'];

        $longest = max(array_map('strlen', explode("\r\n", $body)));
        self::assertLessThanOrEqual(998, $longest);
        preg_match("/filename\\*=UTF-8''([^\r\n;]+)/", $body, $m);
        self::assertSame(str_repeat("\u{1F600}", 49) . '.pdf', rawurldecode($m[1] ?? ''), 'cut between characters, the extension kept');
    }

    /** A 1 000-character subject with no space stayed one line past 998: wordwrap had nowhere to fold it. */
    #[Test]
    public function an_unbreakable_ascii_subject_is_encoded_within_the_line_limit(): void
    {
        $message = $this->message();
        $message->subject = str_repeat('A', 1000);

        $headers = (new MimeBuilder())->build($message)['headers'];

        foreach (explode("\r\n", $headers) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }
        self::assertSame(1, preg_match('/^Subject: ([^\r\n]*(?:\r\n [^\r\n]*)*)/m', $headers, $subject));
        self::assertSame(str_repeat('A', 1000), mb_decode_mimeheader($subject[1]), 'the subject a reader decodes');
    }

    #[Test]
    public function a_line_break_in_a_custom_header_value_cannot_add_a_header(): void
    {
        $message = $this->message();
        $value = "spring\r\nBcc: attacker@evil.example";
        $message->headers = ['X-Campaign' => $value];

        $headers = (new MimeBuilder())->build($message)['headers'];

        self::assertDoesNotMatchRegularExpression('/^Bcc:/mi', $headers);
        self::assertStringContainsString("\r\nX-Campaign: =?UTF-8?B?" . base64_encode($value) . '?=', $headers);
    }

    #[Test]
    public function a_custom_header_name_with_a_trailing_line_break_is_rejected(): void
    {
        // PCRE's $ also matches before a final LF; the name must be matched whole.
        $message = $this->message();
        $message->headers = ["X-Campaign\n" => 'spring'];

        $this->expectException(\InvalidArgumentException::class);
        (new MimeBuilder())->build($message);
    }

    #[Test]
    public function a_long_non_ascii_value_is_split_into_encoded_words_of_at_most_75_characters(): void
    {
        $text = str_repeat('😀', 12) . ' Привіт, світе!';

        foreach ([
            EncodedWord::encode($text),
            (new MailRecipient('bob@example.com', $text))->formatted(),
        ] as $encoded) {
            self::assertGreaterThan(1, preg_match_all('/=\?UTF-8\?B\?[^?]*\?=/', $encoded), $encoded);
            $decoded = '';
            foreach (preg_split('/\r\n /', preg_replace('/\r\n <[^>]*>$/', '', $encoded) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                self::assertLessThanOrEqual(75, strlen($word));
                self::assertMatchesRegularExpression('/^=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=$/', $word);
                $bytes = base64_decode(substr($word, 10, -2), true);
                self::assertIsString($bytes);
                self::assertTrue(mb_check_encoding($bytes, 'UTF-8'), 'A word must not split a UTF-8 character.');
                $decoded .= $bytes;
            }
            self::assertSame($text, $decoded);
        }
    }

    #[Test]
    public function every_header_line_holding_an_encoded_word_fits_in_76_characters(): void
    {
        $message = $this->message();
        // 22 × "é" is one 72-character word: fine alone, 81 characters after "Subject: ".
        $message->subject = str_repeat('é', 22);
        $message->headers = ['X-A-Rather-Long-Custom-Header-Name' => str_repeat('é', 30)];
        $message->to = [
            new MailRecipient('first@example.com', 'Plain'),
            new MailRecipient('a-long-mailbox-name@example.com', str_repeat('ї', 30)),
            // A short encoded name followed by a long unfolded next recipient.
            new MailRecipient('a@b.co', 'é'),
            new MailRecipient(str_repeat('m', 40) . '@example.com'),
        ];

        $headers = (new MimeBuilder())->build($message)['headers'];

        foreach (explode("\r\n", $headers) as $line) {
            if (str_contains($line, '=?UTF-8?')) {
                self::assertLessThanOrEqual(76, strlen($line), $line);
            }
        }
        self::assertStringContainsString('<a-long-mailbox-name@example.com>', $headers);
        self::assertStringContainsString('<a@b.co>', $headers);
        self::assertStringContainsString(str_repeat('m', 40) . '@example.com', $headers);
        self::assertSame(1, preg_match('/^Subject: ([^\r\n]*(?:\r\n [^\r\n]*)*)/m', $headers, $subject));
        self::assertSame(str_repeat('é', 22), mb_decode_mimeheader($subject[1]));
    }

    #[Test]
    public function invalid_utf8_is_not_labelled_utf8(): void
    {
        $encoded = EncodedWord::encode("caf\xC3 ok");

        $bytes = base64_decode(substr($encoded, 10, -2), true);
        self::assertIsString($bytes);
        self::assertTrue(mb_check_encoding($bytes, 'UTF-8'), 'the word is labelled UTF-8, so it must decode to UTF-8');
        self::assertSame("caf\u{FFFD} ok", $bytes, 'the invalid byte is U+FFFD, not mbstring\'s default "?"');
    }

    #[Test]
    public function a_custom_header_name_with_a_line_break_is_rejected(): void
    {
        $message = $this->message();
        $message->headers = ["X-A\r\nBcc" => 'attacker@evil.example'];

        $this->expectException(\InvalidArgumentException::class);
        (new MimeBuilder())->build($message);
    }

    #[Test]
    public function an_address_with_a_line_break_is_rejected(): void
    {
        // It is also sent as `RCPT TO:<...>`, where a CRLF is a new SMTP command.
        $this->expectException(\InvalidArgumentException::class);
        new MailRecipient("victim@example.com>\r\nRCPT TO:<attacker@evil.example");
    }

    #[Test]
    public function a_plain_name_is_still_quoted(): void
    {
        self::assertSame('"Bob \"B\" Smith" <bob@example.com>', (new MailRecipient('bob@example.com', 'Bob "B" Smith'))->formatted());
    }

    /** A 2 000-character subject and sixty named recipients were single lines past the 998 limit. */
    #[Test]
    public function long_ascii_headers_are_folded_within_the_line_limit(): void
    {
        $message = $this->message();
        $message->subject = trim(str_repeat('word ', 400));
        $message->to = array_map(static fn (int $i): MailRecipient => new MailRecipient("user{$i}@example.com", "Recipient Number {$i}"), range(1, 60));

        $headers = (new MimeBuilder())->build($message)['headers'];

        foreach (explode("\r\n", $headers) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
        }
        $unfolded = (string) preg_replace("/\r\n[ \t]/", ' ', $headers);
        self::assertStringContainsString('Subject: ' . $message->subject . "\r\n", $unfolded . "\r\n");
        self::assertStringContainsString('<user60@example.com>', $headers);
    }

    #[Test]
    public function a_local_relay_is_this_machine_or_a_one_word_host_at_an_address_that_is_not_public(): void
    {
        foreach ([['localhost', '127.0.0.1:1025'], ['127.0.0.1', '127.0.0.1:25'], ['::1', '[::1]:25'], ['mailpit', '172.18.0.4:1025'], ['smtp', '10.0.0.7:25'], ['smtp', '[fd00::7]:25']] as [$host, $peer]) {
            self::assertTrue(\Semitexa\Mail\Application\Service\SmtpMailTransport::isLocalRelay($host, $peer), "{$host} at {$peer}");
        }
        foreach ([['smtp.example.com', '10.0.0.7:25'], ['203.0.113.5', '203.0.113.5:25'], ['mail.internal', '10.0.0.7:25'], ['smtp', ''], ['smtp', 'not-an-address']] as [$host, $peer]) {
            self::assertFalse(\Semitexa\Mail\Application\Service\SmtpMailTransport::isLocalRelay($host, $peer), "{$host} at {$peer}");
        }
    }

    /** A resolver's search domain turned `smtp` into a server on the internet, and the password went to it in plain text. */
    #[Test]
    public function a_one_word_host_at_a_public_address_is_not_a_local_relay(): void
    {
        self::assertFalse(\Semitexa\Mail\Application\Service\SmtpMailTransport::isLocalRelay('smtp', '93.184.216.34:25'));
        self::assertFalse(\Semitexa\Mail\Application\Service\SmtpMailTransport::isLocalRelay('mailpit', '[2606:2800:220:1:248:1893:25c8:1946]:25'));
    }

    /** `?: null` turned the contents "0" into no attachment at all. */
    #[Test]
    public function an_attachment_whose_contents_are_zero_is_kept(): void
    {
        $ref = \Semitexa\Mail\Domain\Model\AttachmentReference::fromStored(['filename' => 'zero.txt', 'inlineContentsBase64' => base64_encode('0')]);

        self::assertSame('0', $ref->inlineContents);
    }

    private function message(): PreparedMailMessage
    {
        $message = new PreparedMailMessage();
        $message->messageId = 'id@example.com';
        $message->from = new MailRecipient('app@example.com', 'App');
        $message->to = [new MailRecipient('someone@example.com')];
        $message->subject = 'Hello';
        $message->textBody = 'Hi';

        return $message;
    }
}
