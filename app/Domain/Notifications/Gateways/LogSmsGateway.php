<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Gateways;

use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\Data\SmsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development driver: writes messages to the application log instead of sending them.
 */
final class LogSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $to, string $body): SmsResult
    {
        Log::info('SMS to '.$to, ['body' => $body]);

        return SmsResult::sent('log-'.Str::uuid());
    }
}
