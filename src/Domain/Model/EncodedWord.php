<?php

declare(strict_types=1);

namespace Semitexa\Mail\Domain\Model;

/**
 * RFC 2047 "B" encoding for header text outside printable ASCII.
 *
 * An encoded-word may be at most 75 characters, and a header line holding one
 * at most 76 (RFC 2047 2 and 6.2). A long value therefore becomes several
 * words, split only between UTF-8 characters and folded onto continuation
 * lines; the first word is sized to what is left of the line the caller has
 * already started ("Subject: " and so on). A reader joins adjacent
 * encoded-words without the folding whitespace, so the decoded text is unchanged.
 */
final class EncodedWord
{
    /** RFC 2047 6.2: a header line containing an encoded-word. */
    public const LINE_LIMIT = 76;

    /** RFC 2047 2: one encoded-word. */
    private const WORD_LIMIT = 75;

    private const PREFIX = '=?UTF-8?B?';
    private const SUFFIX = '?=';
    private const FOLD = "\r\n ";

    /**
     * @param int $lineUsed characters already on the line the first word
     *                      continues; LINE_LIMIT starts it on a folded line
     */
    public static function encode(string $text, int $lineUsed = 0): string
    {
        // The words are labelled UTF-8, so they must hold UTF-8: an invalid
        // byte becomes U+FFFD rather than a mislabelled raw byte.
        $text = self::scrub($text);
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false || $characters === []) {
            return '';
        }

        // A continuation line is the fold's single space plus the word.
        $continuationBudget = self::maxBytes(self::WORD_LIMIT);
        $budget = self::maxBytes(min(self::WORD_LIMIT, self::LINE_LIMIT - $lineUsed));
        $out = '';
        if ($budget < strlen($characters[0])) {
            $out = self::FOLD;
            $budget = $continuationBudget;
        }

        $words = [];
        $chunk = '';
        foreach ($characters as $character) {
            if ($chunk !== '' && strlen($chunk) + strlen($character) > $budget) {
                $words[] = self::PREFIX . base64_encode($chunk) . self::SUFFIX;
                $chunk = '';
                $budget = $continuationBudget;
            }
            $chunk .= $character;
        }
        $words[] = self::PREFIX . base64_encode($chunk) . self::SUFFIX;

        return $out . implode(self::FOLD, $words);
    }

    /**
     * Each invalid byte becomes U+FFFD. Not mb_scrub(): it substitutes
     * whatever mb_substitute_character() is set to process-wide, '?' by default.
     */
    private static function scrub(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        // One well-formed UTF-8 character (RFC 3629), or any other single byte.
        preg_match_all(
            '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
                . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}'
                . '|\xF4[\x80-\x8F][\x80-\xBF]{2}|(.)/s',
            $text,
            $matches,
            PREG_SET_ORDER,
        );

        $out = '';
        foreach ($matches as $match) {
            $out .= isset($match[1]) ? "\u{FFFD}" : $match[0];
        }

        return $out;
    }

    /** Payload bytes whose encoded-word fits in $room characters. */
    private static function maxBytes(int $room): int
    {
        $base64 = $room - strlen(self::PREFIX) - strlen(self::SUFFIX);

        return $base64 < 4 ? 0 : intdiv($base64, 4) * 3;
    }
}
