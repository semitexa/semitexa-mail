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
}
