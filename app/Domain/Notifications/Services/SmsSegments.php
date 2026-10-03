<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

/**
 * How many SMS parts a text takes. Any character outside the GSM-7 basic set (e.g. Bangla)
 * switches to UCS-2: 70 characters in one part, 67 per part when split. GSM-7: 160 / 153.
 */
final class SmsSegments
{
    private const string GSM7 = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    public static function isUnicode(string $text): bool
    {
        foreach (mb_str_split($text) as $character) {
            if (! str_contains(self::GSM7, $character)) {
                return true;
            }
        }

        return false;
    }

    public static function count(string $text): int
    {
        $length = mb_strlen($text);

        if ($length === 0) {
            return 0;
        }

        [$single, $multi] = self::isUnicode($text) ? [70, 67] : [160, 153];

        return $length <= $single ? 1 : intdiv($length + $multi - 1, $multi);
    }

    /**
     * "112 characters · Unicode · 2 SMS" style summary for the template editor.
     */
    public static function describe(string $text): string
    {
        return __('sms.length', [
            'chars' => mb_strlen($text),
            'encoding' => self::isUnicode($text) ? 'Unicode' : 'GSM-7',
            'parts' => self::count($text),
        ]);
    }
}
