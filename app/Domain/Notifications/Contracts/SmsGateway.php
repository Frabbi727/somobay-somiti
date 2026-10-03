<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\Data\SmsResult;

/**
 * Sends one text message to a Bangladeshi mobile (01XXXXXXXXX).
 */
interface SmsGateway
{
    public function name(): string;

    public function send(string $to, string $body): SmsResult;
}
