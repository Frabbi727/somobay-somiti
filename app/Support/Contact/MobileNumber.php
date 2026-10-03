<?php

declare(strict_types=1);

namespace App\Support\Contact;

use App\Support\Bangla\BanglaNumber;

/**
 * Bangladeshi mobile numbers, stored in the 11-digit local form 01XXXXXXXXX.
 */
final class MobileNumber
{
    /**
     * Accepts "01712-345678", "+8801712345678", "৮৮০১৭১২৩৪৫৬৭৮" and similar; null if not a valid BD mobile.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', BanglaNumber::toAscii($input)) ?? '';

        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) === 1 ? $digits : null;
    }
}
