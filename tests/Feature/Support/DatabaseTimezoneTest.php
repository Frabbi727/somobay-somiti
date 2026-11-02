<?php

declare(strict_types=1);

use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| Laravel writes timestamps as plain local times ("2025-12-26 18:00:00"). PostgreSQL reads those in
| the session's timezone, so the session must use the app's timezone even when the server runs in
| UTC — otherwise an 18:00 meeting is stored as midnight the next day. CI runs PostgreSQL in UTC.
*/

it('reads plain timestamps in the app timezone whatever the server default is', function (): void {
    expect(DB::selectOne('SHOW TIMEZONE')->TimeZone)->toBe(YearMonth::TIMEZONE);

    DB::statement('CREATE TEMPORARY TABLE tz_probe (at timestamptz)');
    DB::table('tz_probe')->insert(['at' => CarbonImmutable::parse('2025-12-26 18:00', YearMonth::TIMEZONE)]);

    expect(DB::selectOne("SELECT to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS utc FROM tz_probe")->utc)->toBe('2025-12-26 12:00');
});
