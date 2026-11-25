<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Contributions\Actions\ApplyLateFees;
use App\Domain\Contributions\Enums\DueOrigin;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\Members\Actions\ChangeShares;
use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Terms\Data\TermsConsent;
use App\Domain\Members\Terms\Enums\ConsentChannel;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Reporting\MemberReports;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Actions\PrepareYearEnd;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Enums\Role;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

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

/**
 * @return list<string>
 */
function regularMonths(Member $member): array
{
    return Due::query()->where('member_id', $member->id)->where('type', DueType::Deposit)->where('origin', DueOrigin::Regular)
        ->orderBy('month')->get()->map(fn (Due $due): string => (string) $due->month)->all();
}

it('creates regular dues for every already-billed month from the share start', function (): void {
    $member = lateEntry('2026-10');
    $dues = Due::query()->where('member_id', $member->id)->whereIn('type', [DueType::Deposit, DueType::ServiceCharge])->get();

    expect(regularMonths($member))->toBe(['2026-10', '2026-11', '2026-12'])
        ->and($dues)->toHaveCount(6)
        ->and($dues->every(fn (Due $due): bool => $due->origin === DueOrigin::Regular && $due->due_date->toDateString() === '2026-11-25'))->toBeTrue()
        ->and($dues->where('type', DueType::Deposit)->every(fn (Due $due): bool => $due->amount_poisha->equals(Money::ofTaka('2000'))))->toBeTrue()
        ->and($dues->where('type', DueType::ServiceCharge)->every(fn (Due $due): bool => $due->amount_poisha->equals(Money::ofTaka('40'))))->toBeTrue()
        ->and(Due::query()->where('member_id', $member->id)->where('type', DueType::EntryPremium)->exists())->toBeFalse();
});

it('creates one month when the share start is the latest generated month', function (): void {
    expect(regularMonths(lateEntry('2026-12')))->toBe(['2026-12']);
});

it('creates no catch-up dues when the share start is after the latest generated month', function (): void {
    expect(regularMonths(lateEntry('2027-01')))->toBe([]);
});

it('adds nothing when the monthly run is repeated for the catch-up months', function (): void {
    $member = lateEntry('2026-10');
    $before = Due::query()->where('member_id', $member->id)->count();

    foreach (['2026-10', '2026-11', '2026-12'] as $month) {
        generateMonth($month);
    }

    expect(Due::query()->where('member_id', $member->id)->count())->toBe($before);
});

it('charges no late fee for the catch-up months on entry day', function (): void {
    $member = lateEntry('2026-10');

    app(ApplyLateFees::class)(CarbonImmutable::parse('2026-11-25', 'Asia/Dhaka'));

    expect(Due::query()->where('member_id', $member->id)->where('type', DueType::LateFee)->exists())->toBeFalse();
});

it('bills back-dues before the share start and regular dues from it', function (): void {
    config(['somiti.back_dues.enabled' => true, 'somiti.back_dues.entry_premium_bps' => 1000]);

    $member = app(CreateMember::class)(
        userWithRole(Role::Secretary),
        memberData(['mobile' => '01799999999']),
        1,
        YearMonth::parse('2026-10'),
        consent: new TermsConsent(MembershipTerms::current()->version, ConsentChannel::Office, (string) Str::uuid()),
        duesStartMonth: YearMonth::parse('2026-08'),
    );

    $back = Due::query()->where('member_id', $member->id)->where('type', DueType::Deposit)->where('origin', DueOrigin::BackDues)
        ->orderBy('month')->get()->map(fn (Due $due): string => (string) $due->month)->all();

    expect($back)->toBe(['2026-08', '2026-09'])
        ->and(regularMonths($member))->toBe(['2026-10', '2026-11', '2026-12']);
});

it('logs the late-entry dues on the member', function (): void {
    $member = lateEntry('2026-10');

    $log = Activity::query()->where('description', 'late_entry_dues_created')->sole();

    expect($log->subject_id)->toBe($member->id)
        ->and($log->properties['months'])->toBe(['2026-10', '2026-11', '2026-12'])
        ->and($log->properties['amount_poisha'])->toBe(612000);
});

it('dates the registration fee of a late entry on the entry day, so it is not overdue yet', function (): void {
    $member = lateEntry('2026-10');

    $registration = Due::query()->where('member_id', $member->id)->where('type', DueType::Registration)->sole();
    $defaulters = app(MemberReports::class)->defaulters(CarbonImmutable::parse('2026-11-25', 'Asia/Dhaka'));

    expect($registration->due_date->toDateString())->toBe('2026-11-25')
        ->and(collect($defaulters)->contains(fn (array $row): bool => $row['member']->is($member)))->toBeFalse();
});

it('keeps the registration fee of a normal join on its month\'s due day', function (): void {
    $member = lateEntry('2027-01');

    expect(Due::query()->where('member_id', $member->id)->where('type', DueType::Registration)->sole()->due_date->toDateString())
        ->toBe('2027-01-10');
});

it('refuses to add a member while the next month\'s dues are being generated', function (): void {
    $lock = Cache::lock('dues:2027-01', 600);
    $lock->get();

    try {
        expect(lateEntryKey(fn () => lateEntry('2026-10')))->toBe('dues.errors.already_running')
            ->and(Member::query()->where('mobile', '01799999999')->exists())->toBeFalse();
    } finally {
        $lock->release();
    }

    expect(lateEntry('2026-10')->exists)->toBeTrue();
});
