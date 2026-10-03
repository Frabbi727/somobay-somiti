<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\CancelPayment;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Actions\RefundAdvance;
use App\Domain\Contributions\Actions\RejectPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    travelTo('2026-07-05');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500'); // ৳500 + ৳10 service, ৳100 registration per share
    $this->member = onboard(2, '2026-07');
    $this->cashier = userWithRole(Role::Cashier);
    $this->accountant = userWithRole(Role::Accountant);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function paymentData(int $memberId, string $taka, string $method = 'cash', ?string $trx = null, array $extra = []): PaymentData
{
    return PaymentData::fromForm([
        'member_id' => $memberId,
        'method' => $method,
        'amount' => Money::ofTaka($taka),
        'received_on' => '2026-07-05',
        'trx_id' => $trx,
        ...$extra,
    ]);
}

function paymentRuleKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('records a pending payment without posting anything', function (): void {
    $payment = app(RecordPayment::class)($this->cashier, paymentData($this->member->id, '200'));

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->recorded_by)->toBe($this->cashier->id)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('validates payments', function (Closure $data, string $key): void {
    app(RecordPayment::class)($this->cashier, paymentData($this->member->id, '10', 'bkash', 'BKTAKEN01'));

    expect(paymentRuleKey(fn () => app(RecordPayment::class)($this->cashier, $data($this))))->toBe($key);
})->with([
    'bkash without trx' => [fn ($t) => paymentData($t->member->id, '10', 'bkash'), 'payments.errors.trx_required'],
    'bad trx' => [fn ($t) => paymentData($t->member->id, '10', 'nagad', 'x!'), 'payments.errors.trx_required'],
    'duplicate trx' => [fn ($t) => paymentData($t->member->id, '10', 'bkash', 'bktaken01'), 'payments.errors.trx_taken'],
    'zero' => [fn ($t) => paymentData($t->member->id, '0'), 'payments.errors.amount_positive'],
    'future date' => [fn ($t) => paymentData($t->member->id, '10', extra: ['received_on' => '2026-07-06']), 'payments.errors.future_date'],
]);

it('returns the same payment for a repeated idempotency key', function (): void {
    $data = paymentData($this->member->id, '200', extra: ['idempotency_key' => '6f1d2c1e-6d7a-4b6e-9a54-0f3c6b9d2a11']);

    $first = app(RecordPayment::class)($this->cashier, $data);
    $second = app(RecordPayment::class)($this->cashier, $data);

    expect($second->id)->toBe($first->id)
        ->and(paymentRuleKey(fn () => app(RecordPayment::class)($this->cashier, paymentData($this->member->id, '300', extra: ['idempotency_key' => '6f1d2c1e-6d7a-4b6e-9a54-0f3c6b9d2a11']))))
        ->toBe('payments.errors.idempotency_conflict');
});

it('approves by a different user and posts a receipt voucher allocated oldest first', function (): void {
    generateMonth('2026-07');
    travelTo('2026-07-05');
    $payment = app(RecordPayment::class)($this->cashier, paymentData($this->member->id, '1500', 'bkash', 'BK8ZQ1X2'));

    $approved = app(ApprovePayment::class)($this->accountant, $payment);
    $entry = JournalEntry::query()->with('lines.account')->findOrFail($approved->journal_entry_id);

    // Registration 200, then July: service 20 + deposit 1000 (service before deposit by allocation order); 280 left over.
    expect($approved->status)->toBe(PaymentStatus::Approved)
        ->and($entry->voucher_no)->toBe('RV-2026-27-000001')
        ->and($entry->lines->map(fn ($line): array => [$line->account->code, $line->debit_poisha->poisha, $line->credit_poisha->poisha, $line->member_id])->all())
        ->toBe([
            ['1121', 150000, 0, null],
            ['2101', 0, 100000, $this->member->id],
            ['4101', 0, 20000, null],
            ['4111', 0, 2000, null],
            ['2111', 0, 28000, $this->member->id],
        ])
        ->and($approved->allocations()->count())->toBe(3)
        ->and(app(AdvanceLedger::class)->balance($this->member->id)->poisha)->toBe(28000);

    assertBooksTieOut();
});

it('never lets the recorder approve their own payment', function (): void {
    $payment = app(RecordPayment::class)($this->accountant, paymentData($this->member->id, '200'));

    expect(fn () => app(ApprovePayment::class)($this->accountant, $payment))->toThrow(AuthorizationException::class);

    DB::statement('UPDATE payments SET approved_by = recorded_by WHERE id = ?', [$payment->id]);
})->throws(QueryException::class, 'payments_checker_differs');

it('keeps cashiers, auditors and the super admin from approving', function (Role $role): void {
    $payment = app(RecordPayment::class)($this->cashier, paymentData($this->member->id, '200'));

    app(ApprovePayment::class)(userWithRole($role), $payment);
})->throws(AuthorizationException::class)->with([Role::Cashier, Role::Auditor, Role::SuperAdmin, Role::Secretary]);

it('does not approve twice', function (): void {
    $payment = app(RecordPayment::class)($this->cashier, paymentData($this->member->id, '200'));
    app(ApprovePayment::class)($this->accountant, $payment);

    expect(paymentRuleKey(fn () => app(ApprovePayment::class)(userWithRole(Role::President), $payment)))->toBe('payments.errors.already_processed')
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('rejects with a reason and lets the recorder cancel', function (): void {
    $rejected = app(RecordPayment::class)($this->cashier, paymentData($this->member->id, '200'));
    $withdrawn = app(RecordPayment::class)($this->cashier, paymentData($this->member->id, '300'));

    expect(paymentRuleKey(fn () => app(RejectPayment::class)($this->accountant, $rejected, 'no')))->toBe('payments.errors.reason_required')
        ->and(app(RejectPayment::class)($this->accountant, $rejected, 'Cash short by ৳50')->status)->toBe(PaymentStatus::Rejected)
        ->and(fn () => app(CancelPayment::class)($this->accountant, $withdrawn))->toThrow(AuthorizationException::class)
        ->and(app(CancelPayment::class)($this->cashier, $withdrawn)->status)->toBe(PaymentStatus::Cancelled)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('refunds unused advance and needs the president above the threshold', function (): void {
    receivePayment($this->member, '10200'); // 200 registration, 10,000 advance

    expect(paymentRuleKey(fn () => app(RefundAdvance::class)($this->accountant, $this->member, Money::ofTaka('5000'), PaymentMethod::Cash, 'Member asked')))
        ->toBe('payments.errors.refund_needs_president')
        ->and(paymentRuleKey(fn () => app(RefundAdvance::class)(userWithRole(Role::President), $this->member, Money::ofTaka('10000.01'), PaymentMethod::Cash, 'Member asked')))
        ->toBe('payments.errors.refund_exceeds_advance');

    app(RefundAdvance::class)($this->accountant, $this->member, Money::ofTaka('4999.99'), PaymentMethod::Cash, 'Member asked');
    app(RefundAdvance::class)(userWithRole(Role::President), $this->member, Money::ofTaka('5000.01'), PaymentMethod::Bank, 'Member asked');

    expect(app(AdvanceLedger::class)->balance($this->member->id)->poisha)->toBe(0)
        ->and(glBalance('2111'))->toBe(0);

    assertBooksTieOut();
});

it('blocks edits to allocations and advance entries in the database', function (): void {
    receivePayment($this->member, '1000');

    DB::statement('UPDATE advance_ledger_entries SET delta_poisha = delta_poisha + 1');
})->throws(QueryException::class, 'append-only');
