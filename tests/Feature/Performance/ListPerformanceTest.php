<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Enums\Role;
use App\Filament\Pages\Collections\AdvanceBalances;
use App\Filament\Resources\Dues\Pages\ListDues;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Payments\Pages\ListPayments;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

/*
| SOMITI_SPEC.md P7.S2: with 5,000 members, a year of dues and 20,000 payments, staff lists answer in
| under 300 ms (median of three loads after a warm-up, measured server-side).
*/

beforeEach(function (): void {
    travelTo('2027-06-20');
    $this->seed(ChartOfAccountsSeeder::class);
    $accountant = userWithRole(Role::Accountant);
    app(OpenFiscalYear::class)($accountant, 2026);
    $plan = approvedPlan('2026-07', '500');
    $staff = $accountant->id;
    $cashier = userWithRole(Role::Cashier)->id;

    DB::statement(<<<SQL
        INSERT INTO members (member_no, name_bn, name_en, mobile, joined_on, status, created_by, created_at, updated_at)
        SELECT 'M-' || lpad(i::text, 4, '0'), 'সদস্য ' || i, 'Member ' || i, '017' || lpad(i::text, 8, '0'),
               '2026-07-01', 'active', {$staff}, now(), now()
        FROM generate_series(1, 5000) AS i
        SQL);

    DB::statement(<<<SQL
        INSERT INTO share_lots (member_id, shares, effective_from, created_by, created_at, updated_at)
        SELECT id, 1 + id % 3, '2026-07-01', {$staff}, now(), now() FROM members
        SQL);

    DB::statement(<<<SQL
        INSERT INTO dues (member_id, month, type, share_lot_id, adjustment_seq, rate_plan_id, snapshot, amount_poisha, paid_poisha, due_date, status, created_at, updated_at)
        SELECT l.member_id, m.month, 'deposit', l.id, 0, {$plan->id}, '{}'::jsonb, 50000 * l.shares,
               CASE WHEN m.month < '2027-03-01' THEN 50000 * l.shares ELSE 0 END,
               m.month + 9, CASE WHEN m.month < '2027-03-01' THEN 'settled' ELSE 'open' END, now(), now()
        FROM share_lots l CROSS JOIN (SELECT d::date AS month FROM generate_series('2026-07-01'::date, '2027-06-01'::date, '1 month') AS d) AS m
        SQL);

    DB::statement(<<<SQL
        INSERT INTO payments (member_id, method, amount_poisha, received_on, status, idempotency_key, recorded_by, created_at, updated_at)
        SELECT m.id, 'cash', 50000 + (i % 7) * 10000, '2026-07-05'::date + (i % 330), CASE WHEN i % 10 = 0 THEN 'pending' ELSE 'approved' END,
               gen_random_uuid(), {$cashier}, now(), now()
        FROM generate_series(1, 20000) AS i
        JOIN (SELECT id, row_number() OVER (ORDER BY id) AS n FROM members) AS m ON m.n = 1 + (i % 5000)
        SQL);

    DB::statement('ANALYZE');

    Filament::setCurrentPanel('admin');
    $this->actingAs(userWithRole(Role::Accountant));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Median wall time of three requests after one warm-up (views compiled, caches primed).
 */
function medianLoadMs(string $url, Closure $get): float
{
    $get($url)->assertOk();
    $times = [];

    foreach (range(1, 3) as $run) {
        $start = hrtime(true);
        $get($url)->assertOk();
        $times[] = (hrtime(true) - $start) / 1_000_000;
    }

    sort($times);

    return $times[1];
}

it('lists staff pages in under 300 ms with 5,000 members', function (string $page, string $query): void {
    expect(DB::table('members')->count())->toBe(5000)
        ->and(DB::table('dues')->count())->toBe(60000);

    $url = $page::getUrl().$query;
    $ms = medianLoadMs($url, fn (string $url) => $this->get($url));

    expect($ms)->toBeLessThan(300.0, sprintf('%s took %.0f ms', $url, $ms));
})->with([
    'members' => [ListMembers::class, ''],
    'members search' => [ListMembers::class, '?tableSearch=Member+4321'],
    'dues' => [ListDues::class, ''],
    'payments' => [ListPayments::class, ''],
    'advance balances' => [AdvanceBalances::class, ''],
])->group('performance');
