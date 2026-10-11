<?php

declare(strict_types=1);

namespace App\Support\Errors;

use Illuminate\Support\Facades\Context;

/**
 * A short code shown to the user when something fails unexpectedly ("tell your administrator:
 * K7Q2M9XA"). It sits in the log context, so every log line of that request carries it and support
 * can find the technical details.
 */
final class ErrorReference
{
    public const string KEY = 'error_ref';

    /** No 0/O or 1/I, so it can be read out over the phone. */
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function current(): string
    {
        $reference = Context::get(self::KEY);

        if (is_string($reference) && $reference !== '') {
            return $reference;
        }

        $reference = self::generate();
        Context::add(self::KEY, $reference);

        return $reference;
    }

    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
