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
            // Both before the connection: a bad MAIL_ENCRYPTION or a message
            // the builder refuses touched the network first, and could be
            // reported as a network error and retried.
            $encryption = MailerConfig::normalizeEncryption($config->encryption);
            if ($encryption !== null && !in_array($encryption, ['tls', 'ssl'], true)) {
                throw new MailTlsException("MAIL_ENCRYPTION '{$config->encryption}' is not tls or ssl: nothing is sent in plain text instead.");
            }
            $mime = $this->mimeBuilder->build($message);

            $socket = $this->connect($config, $encryption);
            $this->readResponse($socket, 220);

            $ehlo = $this->sendEhlo($socket);

            if ($encryption === 'tls') {
                $this->sendCommand($socket, 'STARTTLS', 220);
                // A failed handshake left the connection in plain text, and
                // AUTH went out over it.
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    throw new MailTlsException("STARTTLS with {$config->host} failed: " . (error_get_last()['message'] ?? 'certificate not trusted, or no common TLS version'));
                }
                $ehlo = $this->sendEhlo($socket);
            }

            if ($config->username !== null && $config->password !== null) {
                $this->authenticate($socket, $config->username, $config->password, $ehlo);
            }

            $this->sendCommand($socket, "MAIL FROM:<{$message->from->email}>", 250);

            $allRecipients = [...$message->to, ...$message->cc, ...$message->bcc];
            foreach ($allRecipients as $recipient) {
                $this->sendCommand($socket, "RCPT TO:<{$recipient->email}>", 250);
            }

            $this->sendCommand($socket, 'DATA', 354);

            $data = $mime['headers'] . "\r\n" . $mime['body'];

            // Dot-stuffing
            $data = str_replace("\r\n.", "\r\n..", $data);

            fwrite($socket, $data . "\r\n.\r\n");
            $dataResponse = $this->readResponse($socket, 250);

            // The server has accepted the message: a failing QUIT or a dropped
            // connection now is not a failed delivery. Counted as one, the
            // worker sent it again and the recipient got it twice.
            try {
                $this->sendCommand($socket, 'QUIT', 221);
            } catch (\Throwable) {
            }

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
        } catch (\InvalidArgumentException $e) {
            // A malformed message (a custom header name the MIME builder
            // refuses): sending it again builds the same message.
            return new MailTransportResult(
                status: MailTransportStatus::Failed,
                errorCode: MailErrorCode::ConfigError->value,
                errorMessage: $e->getMessage(),
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
    private function connect(MailerConfig $config, ?string $encryption)
    {
        $protocol = $encryption === 'ssl' ? 'ssl' : 'tcp';
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
            if ($encryption === 'ssl' && preg_match('/ssl|certificate|tls/i', $reason) === 1) {
                throw new MailTlsException("TLS with {$config->host} failed: {$reason}");
            }
            throw new SmtpException("Connection failed: {$reason} ({$errno})", 0, '');
        }

        stream_set_timeout($socket, $config->timeoutSeconds);

        return $socket;
    }

    /**
     * @param resource $socket
     * @return string the EHLO reply (its AUTH lines name the mechanisms), '' after HELO
     */
    private function sendEhlo($socket): string
    {
        $name = self::clientName($socket);
        try {
            return $this->sendCommand($socket, "EHLO {$name}", 250);
        } catch (SmtpException) {
            $this->sendCommand($socket, "HELO {$name}", 250);

            return '';
        }
    }

    /**
     * @param resource $socket
     */
    private function authenticate($socket, string $username, string $password, string $ehloReply): void
    {
        // LOGIN as before, PLAIN when the server offers only that (LOGIN was
        // the one mechanism, and such servers refused every send).
        if (self::authMechanism($ehloReply) === 'PLAIN') {
            $credentials = base64_encode("\0{$username}\0{$password}");
            fwrite($socket, "AUTH PLAIN {$credentials}\r\n");
            try {
                $this->readResponse($socket, 235);
            } catch (SmtpException $e) {
                // A server that wants the credentials as a continuation (334).
                if ($e->smtpCode !== 334) {
                    throw $e;
                }
                $this->sendCommand($socket, $credentials, 235);
            }

            return;
        }
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
    /**
     * The name EHLO announces: this client's (it sent the server's own name,
     * MAIL_HOST). A host name that is not a full domain (a container's hex id)
     * is refused by strict servers, so it is then the address literal of the
     * local end of the connection.
     *
     * @param resource $socket
     */
    private static function clientName($socket): string
    {
        $name = gethostname();
        if (is_string($name) && self::isDomain($name)) {
            return $name;
        }
        $local = stream_socket_get_name($socket, false);
        $ip = is_string($local) ? preg_replace('/:\d+$/', '', $local) : null;
        if (is_string($ip) && filter_var(trim($ip, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '[IPv6:' . trim($ip, '[]') . ']';
        }

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? "[{$ip}]" : '[127.0.0.1]';
    }

    /** A fully qualified domain name: dot-separated labels, a letters-only last one. */
    public static function isDomain(string $name): bool
    {
        return preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/', $name) === 1;
    }

    /** PLAIN when the EHLO reply offers PLAIN and not LOGIN, LOGIN otherwise. */
    public static function authMechanism(string $ehloReply): string
    {
        preg_match_all('/^250[ -]AUTH[ =](.*)$/mi', $ehloReply, $m);
        $offered = [];
        foreach ($m[1] as $line) {
            $offered = [...$offered, ...(preg_split('/\s+/', strtoupper(trim($line))) ?: [])];
        }

        return in_array('PLAIN', $offered, true) && !in_array('LOGIN', $offered, true) ? 'PLAIN' : 'LOGIN';
    }

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
