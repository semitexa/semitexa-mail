<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

/**
 * RFC 2047 "B" encoding for header text outside printable ASCII.
 *
 * An encoded-word may be at most 75 characters (RFC 2047 2), so a long value
 * becomes several words, split only between UTF-8 characters and folded onto
 * continuation lines. A reader joins adjacent encoded-words without the
 * folding whitespace (RFC 2047 6.2), so the decoded text is unchanged.
 */
final class EncodedWord
{
    private const PREFIX = '=?UTF-8?B?';
    private const SUFFIX = '?=';

    /** 75 - 12 wrapper characters = 63 base64 characters, i.e. at most 45 bytes. */
    private const MAX_BYTES = 45;

    public static function encode(string $text): string
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            // Not valid UTF-8: there are no character boundaries to respect.
            $characters = str_split($text);
        }

        $words = [];
        $chunk = '';
        foreach ($characters as $character) {
            if ($chunk !== '' && strlen($chunk) + strlen($character) > self::MAX_BYTES) {
                $words[] = self::PREFIX . base64_encode($chunk) . self::SUFFIX;
                $chunk = '';
            }
            $chunk .= $character;
        }
        if ($chunk !== '') {
            $words[] = self::PREFIX . base64_encode($chunk) . self::SUFFIX;
        }

        return implode("\r\n ", $words);
    }
}
