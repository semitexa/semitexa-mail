<?php

declare(strict_types=1);

namespace Semitexa\Mail\Exception;

/**
 * The TLS handshake with the mail server failed: an untrusted certificate, a
 * host name that does not match it, no common protocol version. A setup
 * problem, not a network one: retrying does not change the answer.
 */
final class MailTlsException extends \RuntimeException
{
}
