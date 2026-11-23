<?php

declare(strict_types=1);

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\ApplyLateFees;
use App\Domain\Contributions\Actions\CancelBackDues;
use App\Domain\Contributions\BackDues\BackDuesPlanner;
use App\Domain\Contributions\Enums\DueOrigin;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\MemberPosition;
use App\Domain\Members\Terms\Data\TermsConsent;
use App\Domain\Members\Terms\Enums\ConsentChannel;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
| Late joiners (design 2026-11-22 §2): a member who joins in December is billed every month from
| the society's first month at that month's rate, the registration fee once, and an entry premium
| on the back deposits — with no late fines on the back months and no double billing.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    config(['somiti.back_dues.enabled' => true, 'somiti.back_dues.entry_premium_bps' => 1000]);

    approvedPlan('2026-07', '1000', [
        'service_charge_per_share_poisha' => Money::ofTaka('20'),
        'registration_fee_per_share_poisha' => Money::ofTaka('200'),
        'late_fee_mode' => 'fixed',
        'late_fee_fixed_poisha' => Money::ofTaka('50'),
        'late_fee_frequency' => 'once',
    ]);
    onboard(1, '2026-07');

    foreach (['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'] as $month) {
        generateMonth($month);
    }

    travelTo('2026-11-20');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function lateJoiner(string $duesStart = '2026-07', int $shares = 1): Member
{
    return app(CreateMember::class)(
        userWithRole(Role::Secretary),
        memberData(['mobile' => '01799999999']),
        $shares,
        YearMonth::parse('2026-12'),
        consent: new TermsConsent(MembershipTerms::current()->version, ConsentChannel::Office, (string) Str::uuid()),
        duesStartMonth: YearMonth::parse($duesStart),
    );
}

it('bills every back month, the registration fee once and the entry premium: ৳5,800', function (): void {
    $plan = app(BackDuesPlanner::class)->plan(1, YearMonth::parse('2026-07'), YearMonth::parse('2026-12'));

    expect($plan->months)->toHaveCount(5)
        ->and($plan->deposits()->poisha)->toBe(500000)
        ->and($plan->serviceCharges()->poisha)->toBe(10000)
        ->and($plan->registrationFee->poisha)->toBe(20000)
        ->and($plan->premium->poisha)->toBe(50000)
        ->and($plan->total()->poisha)->toBe(580000);

    $member = lateJoiner();
    $dues = Due::query()->where('member_id', $member->id)->get();

    expect($dues->sum(fn (Due $due): int => $due->amount_poisha->poisha))->toBe($plan->total()->poisha)
        ->and($dues->where('type', DueType::Registration)->count())->toBe(1)
        ->and($dues->where('type', DueType::EntryPremium)->sole()->month->equals(YearMonth::parse('2026-12')))->toBeTrue()
        ->and($dues->where('origin', DueOrigin::BackDues)->count())->toBe(11)
        ->and($member->dues_start_month->equals(YearMonth::parse('2026-07')))->toBeTrue()
        ->and(app(MemberPosition::class)->for($member)->oweNow->poisha)->toBe(560000); // registration is due in December
});

it('prices each back month at the rate of that month', function (): void {
    approvedPlan('2027-01', '1200', ['service_charge_per_share_poisha' => Money::ofTaka('20'), 'registration_fee_per_share_poisha' => Money::ofTaka('200')]);

    $plan = app(BackDuesPlanner::class)->plan(1, YearMonth::parse('2026-11'), YearMonth::parse('2027-02'));

    // November and December at ৳1,000, January at ৳1,200; premium 10% of ৳3,200.
    expect(array_map(fn (array $row): int => $row['deposit']->poisha, $plan->months))->toBe([100000, 100000, 120000])
        ->and($plan->premium->poisha)->toBe(32000);
});

it('settles the oldest back month first, so a part payment never marks every month paid', function (): void {
    $member = lateJoiner();
    receivePayment($member, '3000');

    $position = app(MemberPosition::class)->for($member);

    // July 1,020 + August 1,020 settle; September gets 960 of 1,020.
    expect($position->paidThrough?->equals(YearMonth::parse('2026-08')))->toBeTrue()
        ->and($position->paidTotal->poisha)->toBe(300000)
        ->and($position->outstanding->poisha)->toBe(280000);
});

it('never fines back months and never bills them twice', function (): void {
    $member = lateJoiner();

    app(ApplyLateFees::class)(CarbonImmutable::parse('2026-11-30', 'Asia/Dhaka'));
    generateMonth('2026-12');

    $dues = Due::query()->where('member_id', $member->id)->get();

    expect($dues->where('type', DueType::LateFee)->count())->toBe(0)
        ->and($dues->where('type', DueType::Deposit)->count())->toBe(6)
        ->and($dues->where('type', DueType::Deposit)->where('origin', DueOrigin::Regular)->sole()->month->equals(YearMonth::parse('2026-12')))->toBeTrue();
});

it('behaves as before when the dues start with the shares', function (): void {
    $member = lateJoiner('2026-12');

    expect(Due::query()->where('member_id', $member->id)->where('origin', DueOrigin::BackDues)->count())->toBe(0)
        ->and(Due::query()->where('member_id', $member->id)->where('type', DueType::EntryPremium)->count())->toBe(0);
});

it('refuses a dues start it cannot bill, and creates nothing', function (string $duesStart, array $config, string $key, string $month): void {
    config($config);

    expect(fn () => lateJoiner($duesStart))->toThrow(DomainRuleViolation::class, __($key, ['month' => $month]))
        ->and(Member::query()->where('mobile', '01799999999')->exists())->toBeFalse();
})->with([
    'before the society started' => ['2026-06', [], 'members.errors.dues_start_before_society', '2026-07'],
    'after the shares start' => ['2027-01', [], 'members.errors.dues_start_after_shares', '2026-12'],
    'back-dues switched off' => ['2026-07', ['somiti.back_dues.enabled' => false], 'members.errors.back_dues_disabled', ''],
]);

it('posts back deposits to savings and the premium to entry premium income, and the books tie out', function (): void {
    $member = lateJoiner();
    receivePayment($member, '5800');

    $credited = fn (string $code): int => (int) DB::table('journal_lines as l')
        ->join('accounts as a', 'a.id', '=', 'l.account_id')
        ->where('a.code', $code)
        ->where(fn ($query) => $query->where('l.member_id', $member->id)->orWhereNull('l.member_id'))
        ->sum(DB::raw('l.credit_poisha - l.debit_poisha'));

    expect(app(MemberPosition::class)->for($member)->savings->poisha)->toBe(500000)
        ->and($credited(AccountCode::ENTRY_PREMIUM_INCOME))->toBe(50000)
        ->and(app(MemberPosition::class)->for($member)->outstanding->poisha)->toBe(0);

    assertBooksTieOut();
});

it('cancels unpaid back-dues by a second person, with a reason, and keeps the dues start month', function (): void {
    $member = lateJoiner();
    $creator = User::query()->findOrFail($member->created_by);
    $creator->assignRole(Role::Accountant->value);

    expect(fn () => app(CancelBackDues::class)($creator->refresh(), $member, 'Wrong start month'))
        ->toThrow(DomainRuleViolation::class, __('dues.errors.cancel_own_back_dues'))
        ->and(fn () => app(CancelBackDues::class)(userWithRole(Role::Cashier), $member, 'Wrong start month'))
        ->toThrow(AuthorizationException::class);

    $count = app(CancelBackDues::class)(userWithRole(Role::Accountant), $member, 'Wrong start month');

    expect($count)->toBe(11)
        ->and(Due::query()->where('member_id', $member->id)->where('origin', DueOrigin::BackDues)->where('status', 'open')->count())->toBe(0)
        ->and(app(MemberPosition::class)->for($member->refresh())->oweNow->poisha)->toBe(0)
        ->and($member->dues_start_month->equals(YearMonth::parse('2026-07')))->toBeTrue()
        ->and(Activity::query()->where('event', 'back_dues_cancelled')->sole()->properties['reason'])->toBe('Wrong start month');
});

it('refuses to cancel back-dues once anything is paid', function (): void {
    $member = lateJoiner();
    receivePayment($member, '100');

    expect(fn () => app(CancelBackDues::class)(userWithRole(Role::Accountant), $member, 'Wrong start month'))
        ->toThrow(DomainRuleViolation::class, __('dues.errors.back_dues_paid'));
});
