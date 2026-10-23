<?php

declare(strict_types=1);

namespace Semitexa\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Mail\Application\Service\SmtpMailTransport;
use Semitexa\Mail\Domain\Enum\MailErrorCode;

/**
 * Greylisting (450, 451) failed the mail for good and a 554 rejection was
 * retried: retry followed the HTTP reading of the shared error codes.
 */
final class SmtpReplyClassTest extends TestCase
{
    #[Test]
    public function a_4xx_reply_or_a_lost_connection_is_retried_and_a_5xx_reply_is_not(): void
    {
        foreach ([0, 421, 450, 451, 452] as $code) {
            self::assertTrue(SmtpMailTransport::isTemporary($code), "{$code} is temporary");
        }
        foreach ([500, 535, 550, 552, 554] as $code) {
            self::assertFalse(SmtpMailTransport::isTemporary($code), "{$code} is permanent");
        }
    }

    #[Test]
    public function the_worker_retries_the_error_code_of_a_4xx_reply_and_not_of_a_5xx_one(): void
    {
        // The worker decides by error code, not by the transport's status.
        self::assertTrue(MailErrorCode::Provider4xx->isRetryable());
        self::assertFalse(MailErrorCode::Provider5xx->isRetryable());
    }
}
