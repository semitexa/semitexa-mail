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
            foreach (preg_split('/\r\n /', explode(' <', $encoded)[0]) ?: [] as $word) {
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
