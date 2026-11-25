<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\Members\Actions\ChangeShares;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Actions\PrepareYearEnd;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Enums\Role;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/*
| Late data entry (design 2026-11-25): a member who really joined in October is entered in
| November, after dues were generated up to December. Their shares count from October and the
| already-billed months become their normal dues.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->fiscalYear = app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);

    approvedPlan('2026-07', '1000', [
        'service_charge_per_share_poisha' => Money::ofTaka('20'),
        'registration_fee_per_share_poisha' => Money::ofTaka('200'),
        'late_fee_mode' => 'fixed',
        'late_fee_fixed_poisha' => Money::ofTaka('50'),
        'late_fee_frequency' => 'once',
    ]);
    $this->founder = onboard(1, '2026-07');

    foreach (['2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'] as $month) {
        generateMonth($month);
    }

    travelTo('2026-11-25');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function lateEntry(string $from, int $shares = 2): Member
{
    return onboard($shares, $from, ['mobile' => '01799999999']);
}

function lateEntryKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('lets a new member join in a month that already has dues', function (): void {
    $member = lateEntry('2026-10');

    expect((string) $member->shareLots()->sole()->effective_from)->toBe('2026-10');
});

it('still refuses a share change for an existing member in a generated month', function (): void {
    expect(lateEntryKey(fn () => app(ChangeShares::class)(userWithRole(Role::Secretary), $this->founder, 1, YearMonth::parse('2026-10'))))
        ->toBe('members.errors.month_generated');
});

it('refuses a join month before go-live', function (): void {
    OpeningImport::query()->create(['status' => OpeningImportStatus::Posted, 'go_live_month' => YearMonth::parse('2026-08'), 'file_path' => 'p', 'file_name' => 'p', 'file_sha256' => str_repeat('c', 64), 'payload' => [], 'errors' => [], 'summary' => [], 'uploaded_by' => userWithRole(Role::SuperAdmin)->id]);

    expect(lateEntryKey(fn () => lateEntry('2026-07')))->toBe('members.errors.join_before_go_live');
});

it('refuses a join month in a closed fiscal year', function (): void {
    DB::table('fiscal_years')->where('id', $this->fiscalYear->id)->update(['status' => 'closed']);

    expect(lateEntryKey(fn () => lateEntry('2026-10')))->toBe('members.errors.join_year_closed');
});

it('refuses a join month whose year-end is prepared', function (): void {
    travelTo('2027-07-05');
    $accountant = userWithRole(Role::Accountant);
    $fy = FiscalYear::query()->findOrFail($this->fiscalYear->id);

    foreach ($fy->periods()->orderBy('sequence')->get()->slice(0, -1) as $period) {
        app(LockPeriod::class)($accountant, $period);
    }

    app(PrepareYearEnd::class)($accountant, $fy, AppropriationRates::defaults());

    expect(lateEntryKey(fn () => lateEntry('2026-10')))->toBe('members.errors.join_year_end');
});
