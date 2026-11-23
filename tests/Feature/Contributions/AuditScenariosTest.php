<?php

declare(strict_types=1);

/*
| The financial audit's hand-calculated scenarios (docs/audits/financial-ux-audit.md §3). Plan:
| 1 share at ৳500, no service charge, no registration fee, default advance policy. Each test
| states the expectation worked out by hand; S2, S10 and S14 assert the fixes of 2026-11.
*/

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Actions\RefundAdvance;
use App\Domain\Contributions\Actions\ReverseAdvanceRefund;
use App\Domain\Contributions\Actions\ReversePayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Portal\MemberPosition;
use App\Domain\Members\Terms\Data\TermsConsent;
use App\Domain\Members\Terms\Enums\ConsentChannel;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500', [
        'service_charge_per_share_poisha' => Money::zero(),
        'registration_fee_per_share_poisha' => Money::zero(),
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function auditPaidThrough($member): ?string
{
    $m = app(MemberPosition::class)->paidThroughOf($member);

    return $m === null ? null : (string) $m;
}

function auditOutstanding($member): int
{
    return (int) Due::query()->where('member_id', $member->id)->where('status', DueStatus::Open)->sum('outstanding_poisha');
}

function auditAdvance($member): int
{
    return app(AdvanceLedger::class)->balance($member->id)->poisha;
}

it('S1 on-time joiner: Jul due ৳500, pays ৳500 → settled, paid through Jul', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    receivePayment($member, '500');

    expect(depositDues($member))->toBe(['2026-07' => [50000, 50000]])
        ->and(auditOutstanding($member))->toBe(0)
        ->and(auditPaidThrough($member))->toBe('2026-07');
    assertBooksTieOut();
});

it('S2 late joiner: joining in Nov with back-dues bills Jul–Oct at ৳500 each', function (): void {
    config(['somiti.back_dues.enabled' => true]);
    onboard(1, '2026-07'); // someone so the months generate
    foreach (['2026-07', '2026-08', '2026-09', '2026-10'] as $m) {
        generateMonth($m);
    }

    travelTo('2026-10-20');
    $late = app(CreateMember::class)(
        userWithRole(Role::Secretary),
        memberData(['mobile' => '01700000003']),
        1,
        YearMonth::parse('2026-11'),
        consent: new TermsConsent(MembershipTerms::current()->version, ConsentChannel::Office, (string) Str::uuid()),
        duesStartMonth: YearMonth::parse('2026-07'),
    );

    expect(depositDues($late))->toBe([
        '2026-07' => [50000, 0], '2026-08' => [50000, 0], '2026-09' => [50000, 0], '2026-10' => [50000, 0],
    ])->and(auditOutstanding($late))->toBe(200000);

    receivePayment($late, '1500');
    expect(auditOutstanding($late))->toBe(50000)->and(auditPaidThrough($late))->toBe('2026-09');
    assertBooksTieOut();
});

it('S3/S4 partial: 5 months (৳2,500) owed, pays ৳1,500 → Jul–Sep settled, Oct–Nov open, ৳1,000 due, paid through Sep', function (): void {
    $member = onboard(1, '2026-07');
    foreach (['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'] as $m) {
        generateMonth($m);
    }
    travelTo('2026-11-10');
    receivePayment($member, '1500');

    expect(depositDues($member))->toBe([
        '2026-07' => [50000, 50000], '2026-08' => [50000, 50000], '2026-09' => [50000, 50000],
        '2026-10' => [50000, 0], '2026-11' => [50000, 0],
    ])->and(auditOutstanding($member))->toBe(100000)
        ->and(auditPaidThrough($member))->toBe('2026-09');

    receivePayment($member, '1000');
    expect(auditOutstanding($member))->toBe(0)->and(auditPaidThrough($member))->toBe('2026-11');
    assertBooksTieOut();
});

it('S6 under-payment: ৳300 against ৳500 → Jul open with ৳200 left, not marked paid', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    receivePayment($member, '300');

    expect(depositDues($member))->toBe(['2026-07' => [50000, 30000]])
        ->and(auditOutstanding($member))->toBe(20000)
        ->and(auditPaidThrough($member))->toBeNull();
});

it('S5 advance: ৳3,000 in Jul → ৳2,500 advance, used up by Aug–Dec runs', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    receivePayment($member, '3000');
    expect(auditAdvance($member))->toBe(250000);

    foreach (['2026-08', '2026-09', '2026-10', '2026-11', '2026-12'] as $m) {
        generateMonth($m);
    }
    expect(auditAdvance($member))->toBe(0)->and(auditOutstanding($member))->toBe(0)
        ->and(auditPaidThrough($member))->toBe('2026-12');
    assertBooksTieOut();
});

it('S9 reversal: reversing a ৳1,000 payment reopens Jul+Aug and books tie out', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    generateMonth('2026-08');
    $p = receivePayment($member, '1000');
    expect(auditOutstanding($member))->toBe(0);

    app(ReversePayment::class)(userWithRole(Role::President), $p, 'Wrong member');
    expect(auditOutstanding($member))->toBe(100000)->and(auditPaidThrough($member))->toBeNull();
    assertBooksTieOut();
});

it('S10 duplicate cash entry: a second same-day ৳500 needs a reason, so it cannot slip in as advance', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    $data = fn (?string $reason = null) => PaymentData::fromForm([
        'member_id' => $member->id, 'method' => 'cash', 'amount' => Money::ofTaka('500'),
        'received_on' => '2026-07-01', 'trx_id' => null, 'duplicate_reason' => $reason,
    ]);
    app(ApprovePayment::class)(userWithRole(Role::Accountant), app(RecordPayment::class)(userWithRole(Role::Cashier), $data()));

    expect(fn () => app(RecordPayment::class)(userWithRole(Role::Cashier), $data()))->toThrow(DomainRuleViolation::class)
        ->and(Payment::query()->where('member_id', $member->id)->count())->toBe(1)
        ->and(auditAdvance($member))->toBe(0);
});

it('S10b duplicate bKash TrxID is refused', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    receivePayment($member, '500', 'bkash', 'ABC123XYZ');
    expect(fn () => receivePayment($member, '500', 'bkash', 'ABC123XYZ'))->toThrow(DomainRuleViolation::class);
});

it('S14 advance-refund reversal while dues are open pays the dues from the returned advance', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    receivePayment($member, '1500'); // ৳1,000 advance
    $refund = app(RefundAdvance::class)(userWithRole(Role::Accountant), $member, Money::ofTaka('1000'), PaymentMethod::Cash, 'Asked');
    generateMonth('2026-08'); // ৳500 open, no advance
    expect(auditOutstanding($member))->toBe(50000);

    app(ReverseAdvanceRefund::class)(userWithRole(Role::President), $refund, 'Mistake');

    // The ৳1,000 is back in the advance and pays August at once: no advance next to unpaid dues.
    expect(auditOutstanding($member))->toBe(0)->and(auditAdvance($member))->toBe(50000);
    assertBooksTieOut();
});

it('S13 rate change ৳500→৳600 with ৳1,000 advance: Aug 500 from advance, Sep 500 + 100 due', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    receivePayment($member, '1500'); // ৳1,000 advance
    travelTo('2026-07-20');
    approvedPlan('2026-09', '600', [
        'service_charge_per_share_poisha' => Money::zero(),
        'registration_fee_per_share_poisha' => Money::zero(),
    ]);
    generateMonth('2026-08');
    generateMonth('2026-09');

    expect(depositDues($member))->toBe([
        '2026-07' => [50000, 50000], '2026-08' => [50000, 50000], '2026-09' => [60000, 50000],
    ])->and(auditOutstanding($member))->toBe(10000)->and(auditAdvance($member))->toBe(0);
    assertBooksTieOut();
});

it('S9b reversal with advance from another payment: the reopened month is paid from that advance', function (): void {
    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    $first = receivePayment($member, '500');   // settles July
    receivePayment($member, '1000');           // ৳1,000 advance
    expect(auditAdvance($member))->toBe(100000);

    app(ReversePayment::class)(userWithRole(Role::President), $first, 'Wrong member');

    expect(auditOutstanding($member))->toBe(0)
        ->and(auditAdvance($member))->toBe(50000)
        ->and(auditPaidThrough($member))->toBe('2026-07');
    assertBooksTieOut();
});
