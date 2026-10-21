<?php

declare(strict_types=1);

namespace Semitexa\Mail\Application\Service;

use Semitexa\Mail\Exception\MailTlsException;
use Semitexa\Mail\Exception\SmtpException;

use Semitexa\Mail\Domain\Contract\MailTransportInterface;
use Semitexa\Mail\Application\Service\MimeBuilder;
use Semitexa\Mail\Domain\Model\MailerConfig;
use Semitexa\Mail\Domain\Enum\MailErrorCode;
use Semitexa\Mail\Domain\Model\MailTransportResult;
use Semitexa\Mail\Domain\Enum\MailTransportStatus;
use Semitexa\Mail\Domain\Model\PreparedMailMessage;

final class SmtpMailTransport implements MailTransportInterface
{
    private readonly MimeBuilder $mimeBuilder;

    public function __construct()
    {
        $this->mimeBuilder = new MimeBuilder();
    }

    public function key(): string
    {
        return 'smtp';
    }

    public function deliver(PreparedMailMessage $message, MailerConfig $config): MailTransportResult
    {
        $socket = null;
        try {
            $socket = $this->connect($config);
            $this->readResponse($socket, 220);

            $this->sendEhlo($socket, $config);

            if ($config->encryption === 'tls') {
                $this->sendCommand($socket, 'STARTTLS', 220);
                // A failed handshake left the connection in plain text, and
                // AUTH went out over it.
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    throw new MailTlsException("STARTTLS with {$config->host} failed: " . (error_get_last()['message'] ?? 'certificate not trusted, or no common TLS version'));
                }
                $this->sendEhlo($socket, $config);
            }

            if ($config->username !== null && $config->password !== null) {
                $this->authenticate($socket, $config->username, $config->password);
            }

            $this->sendCommand($socket, "MAIL FROM:<{$message->from->email}>", 250);

            $allRecipients = [...$message->to, ...$message->cc, ...$message->bcc];
            foreach ($allRecipients as $recipient) {
                $this->sendCommand($socket, "RCPT TO:<{$recipient->email}>", 250);
            }

            $this->sendCommand($socket, 'DATA', 354);

            $mime = $this->mimeBuilder->build($message);
            $data = $mime['headers'] . "\r\n" . $mime['body'];

            // Dot-stuffing
            $data = str_replace("\r\n.", "\r\n..", $data);

            fwrite($socket, $data . "\r\n.\r\n");
            $dataResponse = $this->readResponse($socket, 250);

            $this->sendCommand($socket, 'QUIT', 221);

            return new MailTransportResult(
                status: MailTransportStatus::Accepted,
                providerMessageId: $message->messageId,
                providerStatus: 'sent',
                providerResponse: ['smtp_response' => $dataResponse],
            );
        } catch (SmtpException $e) {
            $errorCode = $this->classifySmtpError($e);
            return new MailTransportResult(
                status: self::isTemporary($e->smtpCode) ? MailTransportStatus::Deferred : MailTransportStatus::Failed,
                errorCode: $errorCode->value,
                errorMessage: $e->getMessage(),
                providerResponse: ['smtp_code' => $e->smtpCode, 'smtp_response' => $e->smtpResponse],
            );
        } catch (MailTlsException $e) {
            // Not network_error: the worker retries that, and a certificate
            // problem was retried four times, a minute apart.
            return new MailTransportResult(
                status: MailTransportStatus::Failed,
                errorCode: MailErrorCode::ConfigError->value,
                errorMessage: $e->getMessage(),
            );
        } catch (\Throwable $e) {
            return new MailTransportResult(
                status: MailTransportStatus::Failed,
                errorCode: MailErrorCode::NetworkError->value,
                errorMessage: $e->getMessage(),
            );
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * @return resource
     */
    private function connect(MailerConfig $config)
    {
        $protocol = $config->encryption === 'ssl' ? 'ssl' : 'tcp';
        $address = "{$protocol}://{$config->host}:{$config->port}";

        // Certificates are checked: with checking off, anyone on the path
        // could read the mail and the AUTH password. MAIL_VERIFY_PEER=false
        // is for a local test server with a self-signed certificate.
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $config->verifyPeer,
            'verify_peer_name' => $config->verifyPeer,
            'allow_self_signed' => !$config->verifyPeer,
            'peer_name' => $config->host,
        ]]);

        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $config->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            // With ssl:// a certificate failure reports errno 0 and an empty
            // errstr; the reason is only in the last PHP warning.
            $reason = trim($errstr) !== '' ? $errstr : (string) (error_get_last()['message'] ?? '');
            if ($config->encryption === 'ssl' && preg_match('/ssl|certificate|tls/i', $reason) === 1) {
                throw new MailTlsException("TLS with {$config->host} failed: {$reason}");
            }
            throw new SmtpException("Connection failed: {$reason} ({$errno})", 0, '');
        }

        stream_set_timeout($socket, $config->timeoutSeconds);

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function sendEhlo($socket, MailerConfig $config): void
    {
        try {
            $this->sendCommand($socket, "EHLO {$config->host}", 250);
        } catch (SmtpException) {
            $this->sendCommand($socket, "HELO {$config->host}", 250);
        }
    }

    /**
     * @param resource $socket
     */
    private function authenticate($socket, string $username, string $password): void
    {
        $this->sendCommand($socket, 'AUTH LOGIN', 334);
        $this->sendCommand($socket, base64_encode($username), 334);
        $this->sendCommand($socket, base64_encode($password), 235);
    }

    /**
     * @param resource $socket
     */
    private function sendCommand($socket, string $command, int $expectedCode): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->readResponse($socket, $expectedCode);
    }

    /**
     * @param resource $socket
     */
    private function readResponse($socket, int $expectedCode): string
    {
        $response = '';
        while (true) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                throw new SmtpException('Connection lost reading response', 0, $response);
            }
            $response .= $line;
            // Multi-line response continues with "code-", ends with "code "
            if (isset($line[3]) && $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new SmtpException("Expected {$expectedCode}, got {$code}: {$response}", $code, $response);
        }

        return $response;
    }

    /**
     * In SMTP a 4xx reply is temporary (greylisting answers 450/451) and a 5xx
     * one permanent; 0 is a lost connection. The shared error codes say the
     * opposite for HTTP providers, which is how greylisted mail failed for
     * good and a 554 rejection was retried.
     */
    public static function isTemporary(int $smtpCode): bool
    {
        return $smtpCode === 0 || ($smtpCode >= 400 && $smtpCode < 500);
    }

    private function classifySmtpError(SmtpException $e): MailErrorCode
    {
        $code = $e->smtpCode;

        if ($code === 0) {
            return MailErrorCode::NetworkError;
        }

        if ($code === 421) {
            return MailErrorCode::RateLimited;
        }

        if ($code === 535 || $code === 534) {
            return MailErrorCode::AuthError;
        }

        if ($code >= 550 && $code <= 553) {
            return MailErrorCode::InvalidRecipient;
        }

        if ($code >= 400 && $code < 500) {
            return MailErrorCode::Provider4xx;
        }

        if ($code >= 500) {
            return MailErrorCode::Provider5xx;
        }

        return MailErrorCode::UnknownTransportError;
    }
}
