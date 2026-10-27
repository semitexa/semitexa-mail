<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mail\Application\Service\EnvMailerConfigResolver;
use Semitexa\Mail\Application\Service\MailTransportRegistry;
use Semitexa\Mail\Application\Service\MimeBuilder;
use Semitexa\Mail\Domain\Model\MailRecipient;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;

/** Small mail defects found writing runtime/mail.md. */
final class MailSmallFixesTest extends TestCase
{
    protected function tearDown(): void
    {
        MailTransportRegistry::reset();
        putenv('MAIL_ENCRYPTION');
    }

    #[Test]
    public function a_transport_registered_before_the_first_get_is_not_overwritten(): void
    {
        // boot() runs on the first get() and replaced an `smtp` registered before it.
        MailTransportRegistry::reset();
        $mine = new class implements \Semitexa\Mail\Domain\Contract\MailTransportInterface {
            public function key(): string
            {
                return 'smtp';
            }

            public function deliver(PreparedMailMessage $message, \Semitexa\Mail\Domain\Model\MailerConfig $config): \Semitexa\Mail\Domain\Model\MailTransportResult
            {
                return new \Semitexa\Mail\Domain\Model\MailTransportResult(status: \Semitexa\Mail\Domain\Enum\MailTransportStatus::Accepted);
            }
        };
        MailTransportRegistry::register($mine);

        self::assertSame($mine, MailTransportRegistry::get('smtp'));
    }

    #[Test]
    public function mail_encryption_is_read_whatever_its_case_and_starttls_means_tls(): void
    {
        // `TLS` or `starttls` connected in plain text.
        foreach (['TLS' => 'tls', ' starttls ' => 'tls', 'SSL' => 'ssl', 'none' => null, 'bogus' => 'bogus'] as $raw => $expected) {
            putenv("MAIL_ENCRYPTION={$raw}");
            self::assertSame($expected, (new EnvMailerConfigResolver())->resolve()->encryption, "MAIL_ENCRYPTION={$raw}");
        }
    }

    #[Test]
    public function the_message_id_carries_the_senders_domain(): void
    {
        $message = new PreparedMailMessage();
        $message->messageId = '01a15c00-0000-7000-8000-000000000001';
        $message->from = new MailRecipient('noreply@shop.example');
        $message->to = [new MailRecipient('ada@example.test')];
        $message->textBody = 'x';

        $headers = (new MimeBuilder())->build($message)['headers'];

        self::assertStringContainsString('Message-ID: <01a15c00-0000-7000-8000-000000000001@shop.example>', $headers);
    }

    #[Test]
    public function a_bad_encryption_value_or_header_is_refused_before_any_connection(): void
    {
        // Port 1 on the loopback refuses: had it connected first, this would be
        // network_error and retried.
        $config = new \Semitexa\Mail\Domain\Model\MailerConfig(host: '127.0.0.1', port: 1, encryption: 'tsl', timeoutSeconds: 2);
        $result = (new \Semitexa\Mail\Application\Service\SmtpMailTransport())->deliver($this->message(), $config);
        self::assertSame('config_error', $result->errorCode);

        $message = $this->message();
        $message->headers = ["X-Bad\r\nName" => 'v'];
        $config = new \Semitexa\Mail\Domain\Model\MailerConfig(host: '127.0.0.1', port: 1, timeoutSeconds: 2);
        $result = (new \Semitexa\Mail\Application\Service\SmtpMailTransport())->deliver($message, $config);
        self::assertSame('config_error', $result->errorCode);
    }

    #[Test]
    public function only_a_full_domain_counts_as_one(): void
    {
        foreach (['mail.example.com', 'a.io'] as $ok) {
            self::assertTrue(\Semitexa\Mail\Application\Service\SmtpMailTransport::isDomain($ok), $ok);
        }
        foreach (['3f2a9c1b7d4e', 'localhost', 'a..b.com', '-a.com', 'a.com.', 'x.123'] as $bad) {
            self::assertFalse(\Semitexa\Mail\Application\Service\SmtpMailTransport::isDomain($bad), $bad);
        }
    }

    #[Test]
    public function an_odd_sender_domain_falls_back_to_localhost_in_the_message_id(): void
    {
        $message = $this->message();
        $message->from = new MailRecipient('a@[10.0.0.1]');

        self::assertStringContainsString('@localhost>', (new MimeBuilder())->build($message)['headers']);
    }

    private function message(): PreparedMailMessage
    {
        $message = new PreparedMailMessage();
        $message->messageId = 'm-1';
        $message->from = new MailRecipient('noreply@shop.example');
        $message->to = [new MailRecipient('ada@example.test')];
        $message->textBody = 'x';

        return $message;
    }

    #[Test]
    public function auth_plain_is_used_when_the_server_offers_it_and_not_login(): void
    {
        // Only AUTH LOGIN was spoken; some servers offer only PLAIN.
        self::assertSame('PLAIN', \Semitexa\Mail\Application\Service\SmtpMailTransport::authMechanism("250-mail.example\r\n250-AUTH PLAIN\r\n250 8BITMIME\r\n"));
        self::assertSame('LOGIN', \Semitexa\Mail\Application\Service\SmtpMailTransport::authMechanism("250-mail.example\r\n250 AUTH LOGIN PLAIN CRAM-MD5\r\n"), 'LOGIN, as before, when both are offered');
        self::assertSame('PLAIN', \Semitexa\Mail\Application\Service\SmtpMailTransport::authMechanism("250-mail.example\r\n250-AUTH=CRAM-MD5\r\n250 AUTH PLAIN\r\n"), 'every AUTH line counts');
        self::assertSame('LOGIN', \Semitexa\Mail\Application\Service\SmtpMailTransport::authMechanism("250-mail.example\r\n250 AUTH LOGIN\r\n"));
        self::assertSame('LOGIN', \Semitexa\Mail\Application\Service\SmtpMailTransport::authMechanism(''));
    }

    /**
     * A server without STARTTLS answers 502: it was provider_5xx, which a
     * resend under the mail's key takes for refused for good, so the mail was
     * never sent after MAIL_ENCRYPTION was fixed. A temporary 454 stays deferred.
     */
    #[Test]
    public function a_starttls_the_server_does_not_offer_is_a_config_error_and_a_temporary_refusal_is_deferred(): void
    {
        foreach (['502 5.5.1 STARTTLS not supported' => ['failed', 'config_error'], '454 4.7.0 TLS not available' => ['deferred', 'provider_4xx']] as $reply => [$status, $code]) {
            [$process, $port] = self::smtpServerAnsweringStarttls($reply);
            try {
                $result = (new \Semitexa\Mail\Application\Service\SmtpMailTransport())->deliver(
                    $this->message(),
                    new \Semitexa\Mail\Domain\Model\MailerConfig(host: '127.0.0.1', port: $port, encryption: 'tls', timeoutSeconds: 5),
                );
            } finally {
                proc_terminate($process);
                proc_close($process);
            }
            self::assertSame($status, $result->status->value, $reply);
            self::assertSame($code, $result->errorCode, $reply);
        }
    }

    /** @return array{resource, int} a one-connection SMTP server: greets, answers EHLO, then $reply to STARTTLS */
    private static function smtpServerAnsweringStarttls(string $reply): array
    {
        $script = '$s = stream_socket_server("tcp://127.0.0.1:0"); fwrite(STDOUT, stream_socket_get_name($s, false) . "\n"); fflush(STDOUT);'
            . '$c = stream_socket_accept($s, 10); fwrite($c, "220 test ESMTP\r\n");'
            . 'while (($l = fgets($c)) !== false) { $l = strtoupper(trim($l));'
            . 'if (str_starts_with($l, "EHLO")) { fwrite($c, "250-test\r\n250 STARTTLS\r\n"); }'
            . 'elseif ($l === "STARTTLS") { fwrite($c, ' . var_export($reply . "\r\n", true) . '); }'
            . 'elseif ($l === "QUIT") { fwrite($c, "221 bye\r\n"); break; } else { fwrite($c, "500 what\r\n"); } }';
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $address = trim((string) fgets($pipes[1]));

        return [$process, (int) substr($address, strrpos($address, ':') + 1)];
    }

    /** One address refused for good (a mistyped cc) failed the mail for everyone. */
    #[Test]
    public function a_recipient_refused_for_good_is_skipped_and_reported_and_the_others_get_the_mail(): void
    {
        $message = $this->message();
        $message->cc = [new MailRecipient('typo@nowhere.test'), new MailRecipient('full@example.test')];
        // A full mailbox (552 5.2.2) refuses that one recipient, not the mail.
        [$process, $port] = self::smtpServer(['typo@nowhere.test' => '550 5.1.1 no such user', 'full@example.test' => '552 5.2.2 mailbox full']);
        try {
            $result = (new \Semitexa\Mail\Application\Service\SmtpMailTransport())->deliver($message, new \Semitexa\Mail\Domain\Model\MailerConfig(host: '127.0.0.1', port: $port, timeoutSeconds: 5));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

        self::assertSame('accepted', $result->status->value, (string) $result->errorMessage);
        self::assertSame('sent_partially', $result->providerStatus);
        self::assertSame('typo@nowhere.test', $result->providerResponse['refused_recipients'][0]['email'] ?? null);
        self::assertSame(550, $result->providerResponse['refused_recipients'][0]['smtp_code'] ?? null);
        self::assertStringContainsString('typo@nowhere.test (550', (string) $result->errorMessage, 'said on the row, which reads sent');
        self::assertSame(552, $result->providerResponse['refused_recipients'][1]['smtp_code'] ?? null);
    }

    #[Test]
    public function every_recipient_refused_fails_the_mail_and_a_temporary_refusal_defers_all(): void
    {
        // "Too many recipients", whatever its code: the mail fails at once, not the rest dropped, not retried.
        foreach ([['550 5.1.1 no such user', 'failed'], ['451 4.3.0 try later', 'deferred'], ['552 5.5.3 too many recipients', 'failed'], ['452 4.5.3 too many recipients', 'failed'], ['554 5.5.3 recipient limit', 'failed']] as [$reply, $status]) {
            $message = $this->message();
            $message->cc = [new MailRecipient('second@example.test')];
            [$process, $port] = self::smtpServer(['ada@example.test' => $reply, 'second@example.test' => $reply]);
            try {
                $result = (new \Semitexa\Mail\Application\Service\SmtpMailTransport())->deliver($message, new \Semitexa\Mail\Domain\Model\MailerConfig(host: '127.0.0.1', port: $port, timeoutSeconds: 5));
            } finally {
                proc_terminate($process);
                proc_close($process);
            }
            self::assertSame($status, $result->status->value, $reply);
            if (str_contains($reply, '.5.3')) {
                self::assertSame('invalid_recipient', $result->errorCode, $reply);
            }
        }
    }

    /** A legacy server's 8-bit reply reaches the sent row ASCII-only, on one line. */
    #[Test]
    public function a_refusal_reply_is_stored_as_one_ascii_line_without_its_code_twice(): void
    {
        $message = $this->message();
        $message->cc = [new MailRecipient('typo@nowhere.test')];
        [$process, $port] = self::smtpServer(['typo@nowhere.test' => "550-5.1.1 Empf\xE4nger\r\n550 5.1.1 unbekannt"]);
        try {
            $result = (new \Semitexa\Mail\Application\Service\SmtpMailTransport())->deliver($message, new \Semitexa\Mail\Domain\Model\MailerConfig(host: '127.0.0.1', port: $port, timeoutSeconds: 5));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

        self::assertSame('accepted', $result->status->value, (string) $result->errorMessage);
        self::assertSame('Not delivered to: typo@nowhere.test (550-5.1.1 Empf?nger 550 5.1.1 unbekannt)', $result->errorMessage);
    }

    /** An address that is not ASCII went out as raw 8-bit bytes to any server. */
    #[Test]
    public function a_non_ascii_address_needs_smtputf8_and_is_sent_with_it(): void
    {
        foreach ([false => 'failed', true => 'accepted'] as $smtpUtf8 => $status) {
            $message = $this->message();
            $message->to = [new MailRecipient('андрій@example.test')];
            [$process, $port] = self::smtpServer([], (bool) $smtpUtf8);
            try {
                $result = (new \Semitexa\Mail\Application\Service\SmtpMailTransport())->deliver($message, new \Semitexa\Mail\Domain\Model\MailerConfig(host: '127.0.0.1', port: $port, timeoutSeconds: 5));
            } finally {
                proc_terminate($process);
                proc_close($process);
            }
            self::assertSame($status, $result->status->value, (string) $result->errorMessage);
            if (!$smtpUtf8) {
                // Not invalid_recipient: again() never resends that, and the fix is a setup one.
                self::assertSame('config_error', $result->errorCode);
                self::assertStringContainsString('андрій@example.test needs SMTPUTF8', (string) $result->errorMessage);
                self::assertStringNotContainsString('intl', (string) $result->errorMessage, 'intl cannot help a local part');
            }
        }
    }

    #[Test]
    public function a_non_ascii_domain_goes_as_punycode_with_ext_intl(): void
    {
        $message = $this->message();
        $message->to = [new MailRecipient('ada@приклад.укр'), new MailRecipient('bob@приклад.укр')];
        [$process, $port] = self::smtpServer(['ada@xn--80aikifvh.xn--j1amh' => '250 ok punycode', 'bob@xn--80aikifvh.xn--j1amh' => '550 5.1.1 no such user']);
        try {
            $result = (new \Semitexa\Mail\Application\Service\SmtpMailTransport())->deliver($message, new \Semitexa\Mail\Domain\Model\MailerConfig(host: '127.0.0.1', port: $port, timeoutSeconds: 5));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
        if (function_exists('idn_to_ascii')) {
            self::assertSame('accepted', $result->status->value, (string) $result->errorMessage);
            self::assertSame('sent_partially', $result->providerStatus);
            self::assertSame('Not delivered to: bob@приклад.укр (550 5.1.1 no such user)', $result->errorMessage, 'the stored address, not its punycode');
        } else {
            self::assertSame('failed', $result->status->value);
            self::assertStringContainsString('PHP intl extension', (string) $result->errorMessage);
        }
    }

    #[Test]
    public function an_address_that_is_not_utf8_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MailRecipient("ada\xE4@example.test");
    }

    /**
     * A one-connection SMTP server for a plain session; RCPT for an address
     * in $refusals answers that reply.
     *
     * @param array<string, string> $refusals
     * @return array{resource, int}
     */
    private static function smtpServer(array $refusals, bool $smtpUtf8 = false): array
    {
        $script = '$r = ' . var_export($refusals, true) . '; $u8 = ' . var_export($smtpUtf8, true) . '; $s = stream_socket_server("tcp://127.0.0.1:0"); fwrite(STDOUT, stream_socket_get_name($s, false) . "\n"); fflush(STDOUT);'
            . '$c = stream_socket_accept($s, 10); fwrite($c, "220 test ESMTP\r\n"); $data = false;'
            . 'while (($l = fgets($c)) !== false) { if ($data) { if (rtrim($l, "\r\n") === ".") { $data = false; fwrite($c, "250 queued\r\n"); } continue; }'
            . '$u = strtoupper(trim($l));'
            . 'if (str_starts_with($u, "EHLO")) { fwrite($c, $u8 ? "250-test\r\n250 SMTPUTF8\r\n" : "250 test\r\n"); }'
            . 'elseif (str_starts_with($u, "MAIL FROM")) { fwrite($c, $u8 && !str_ends_with($u, " SMTPUTF8") ? "555 no SMTPUTF8 parameter\r\n" : "250 ok\r\n"); }'
            . 'elseif (str_starts_with($u, "RCPT TO")) { preg_match("/<([^>]*)>/", $l, $m); fwrite($c, ($r[$m[1]] ?? "250 ok") . "\r\n"); }'
            . 'elseif ($u === "DATA") { $data = true; fwrite($c, "354 go\r\n"); }'
            . 'elseif ($u === "QUIT") { fwrite($c, "221 bye\r\n"); break; } else { fwrite($c, "500 what\r\n"); } }';
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $address = trim((string) fgets($pipes[1]));

        return [$process, (int) substr($address, strrpos($address, ':') + 1)];
    }
}
