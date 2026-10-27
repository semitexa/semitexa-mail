<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

/**
 * The bodies of a sensitive mail ({@see MailEnvelope::$sensitive}) go from its
 * stored row once it is sent: the row is read by every operator screen and
 * backup, and a live reset link worked from there.
 */
final class SensitiveMailBodies
{
    /** The metadata key a sensitive envelope leaves on its row. */
    public const METADATA_KEY = 'semitexa.sensitive';

    public const ERASED = '[erased once sent: it carried a secret]';

    private function __construct()
    {
    }

    /** The message to save as sent: its bodies erased when it is sensitive, as it is otherwise. */
    public static function erasedIfSensitive(MailMessage $message): MailMessage
    {
        if (($message->getMetadata()[self::METADATA_KEY] ?? false) !== true) {
            return $message;
        }

        return $message->with([
            'htmlBody' => $message->getHtmlBody() === null ? null : self::ERASED,
            'textBody' => $message->getTextBody() === null ? null : self::ERASED,
        ]);
    }
}
