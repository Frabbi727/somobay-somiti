<?php

declare(strict_types=1);

use App\Domain\Notifications\Services\SmsSegments;

it('counts GSM-7 and Unicode parts', function (string $text, bool $unicode, int $parts): void {
    expect(SmsSegments::isUnicode($text))->toBe($unicode)
        ->and(SmsSegments::count($text))->toBe($parts);
})->with([
    'empty' => ['', false, 0],
    'short english' => ['Your instalment is due.', false, 1],
    '160 gsm' => [str_repeat('a', 160), false, 1],
    '161 gsm' => [str_repeat('a', 161), false, 2],
    'bangla 70' => [str_repeat('ক', 70), true, 1],
    'bangla 71' => [str_repeat('ক', 71), true, 2],
    'bangla 135' => [str_repeat('ক', 135), true, 3],
    'taka sign' => ['৳500 due', true, 1],
]);
