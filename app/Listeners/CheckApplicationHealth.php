<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Makes /up (uptime monitoring) fail when the database or cache is unreachable, not only when PHP is down.
 */
final class CheckApplicationHealth
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::select('SELECT 1');

        Cache::put('health-check', true, 10);
    }
}
