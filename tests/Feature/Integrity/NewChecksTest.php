<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Integrity\Checks\DuesCompleteness;
use App\Domain\Integrity\Checks\ReceiptFigures;
use App\Domain\Integrity\Checks\StrandedAdvance;
use App\Domain\Integrity\Finding;
use App\Enums\Role;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| The three nightly checks added with the late-joiner and frozen-receipt work (design 2026-11-22
| §8): each stays quiet on healthy books and reports the one problem it exists for.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    $this->plan = approvedPlan('2026-07', '500', ['service_charge_per_share_poisha' => Money::zero(), 'registration_fee_per_share_poisha' => Money::zero()]);
    $this->member = onboard(1, '2026-07');
    generateMonth('2026-07');
    generateMonth('2026-08');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return list<string>
 */
function findingsOf(string $check): array
{
    return array_map(fn (Finding $finding): string => $finding->message, app($check)->run());
}

it('is quiet on healthy books', function (): void {
    receivePayment($this->member, '1500'); // July + August + ৳500 advance

    expect(findingsOf(StrandedAdvance::class))->toBe([])
        ->and(findingsOf(DuesCompleteness::class))->toBe([])
        ->and(findingsOf(ReceiptFigures::class))->toBe([]);
});

it('reports an advance left next to an open due of a generated month', function (): void {
    receivePayment($this->member, '1500');

    Due::query()->create([
        'member_id' => $this->member->id, 'month' => YearMonth::parse('2026-08'), 'type' => DueType::LateFee,
        'share_lot_id' => null, 'adjustment_seq' => 0, 'rate_plan_id' => $this->plan->id, 'snapshot' => $this->plan->snapshot(),
        'amount_poisha' => Money::ofTaka('20'), 'paid_poisha' => Money::zero(), 'due_date' => '2026-08-10', 'status' => DueStatus::Open,
    ]);

    expect(findingsOf(StrandedAdvance::class))->toHaveCount(1)
        ->and(findingsOf(StrandedAdvance::class)[0])->toContain('late_fee 2026-08');
});

it('reports a generated month with no deposit due for an active member', function (): void {
    $id = DB::table('members')->insertGetId([
        'member_no' => 'M-9999', 'name_bn' => 'পরীক্ষা', 'name_en' => 'Test', 'mobile' => '01799999998',
        'joined_on' => '2026-07-01', 'dues_start_month' => '2026-07-01', 'status' => 'active',
        'created_by' => $this->member->created_by, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('share_lots')->insert(['member_id' => $id, 'shares' => 1, 'effective_from' => '2026-07-01', 'created_by' => $this->member->created_by, 'created_at' => now(), 'updated_at' => now()]);

    expect(findingsOf(DuesCompleteness::class))->toBe([
        "Member {$id} has no deposit due for 2026-07",
        "Member {$id} has no deposit due for 2026-08",
    ]);
});

it('reports a frozen receipt that does not add up', function (): void {
    $id = DB::table('payments')->insertGetId([
        'member_id' => $this->member->id, 'method' => 'cash', 'amount_poisha' => 50000, 'received_on' => '2026-08-01',
        'status' => 'approved', 'idempotency_key' => (string) Str::uuid(), 'recorded_by' => $this->member->created_by,
        'outstanding_before_poisha' => 100000, 'outstanding_after_poisha' => 0, 'other_owed_after_poisha' => 0, 'advance_after_poisha' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(findingsOf(ReceiptFigures::class))->toBe(["Receipt of payment {$id}: owed before 100000 less 0 on dues is not the owed after 0"]);
});
