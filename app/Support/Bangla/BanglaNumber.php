<?php

declare(strict_types=1);

namespace App\Support\Bangla;

/**
 * Display-time conversion between ASCII and Bangla digits, plus digit grouping.
 */
final class BanglaNumber
{
    private const array TO_BANGLA = [
        '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪',
        '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯',
    ];

    /**
     * Replace ASCII digits with Bangla digits, leaving every other character untouched.
     */
    public static function digits(int|string $value): string
    {
        return strtr((string) $value, self::TO_BANGLA);
    }

    /**
     * Replace Bangla digits with ASCII digits, leaving every other character untouched.
     */
    public static function toAscii(string $value): string
    {
        return strtr($value, array_flip(self::TO_BANGLA));
    }

    /**
     * Group a string of unsigned ASCII digits: 1,234,567 (western) or 12,34,567 (lakh).
     */
    public static function group(string $digits, bool $lakh = false): string
    {
        if (strlen($digits) <= 3) {
            return $digits;
        }

        $lastThree = substr($digits, -3);
        $rest = substr($digits, 0, -3);
        $size = $lakh ? 2 : 3;

        $groups = [];

        while (strlen($rest) > $size) {
            array_unshift($groups, substr($rest, -$size));
            $rest = substr($rest, 0, -$size);
        }

        array_unshift($groups, $rest);
        $groups[] = $lastThree;

        return implode(',', $groups);
    }

    /**
     * Format an integer with grouping, in Bangla digits for the "bn" locale.
     */
    public static function format(int $number, string $locale = 'bn', bool $lakh = false): string
    {
        $text = (string) $number;
        $sign = str_starts_with($text, '-') ? '-' : '';
        $grouped = $sign.self::group(ltrim($text, '-'), $lakh);

        return $locale === 'bn' ? self::digits($grouped) : $grouped;
    }
}
