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
}
