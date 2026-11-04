<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\RefundAdvance;
use App\Domain\Contributions\Actions\ReverseAdvanceRefund;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Pages\Collections\AdvanceRefunds;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Undoing an advance refund made by mistake: the money comes back into 2111, the member gets the
| advance back, and the refund stays on record.
*/

beforeEach(function (): void {
    travelTo('2026-07-05');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    approvedPlan('2026-07', '500');
    $this->member = onboard(2, '2026-07');
    receivePayment($this->member, '3200'); // 200 registration, 3,000 advance

    $this->refund = app(RefundAdvance::class)($this->accountant, $this->member, Money::ofTaka('1000'), PaymentMethod::Cash, 'Member asked');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function refundReversalRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('reverses a refund: the cash and the member advance come back, the refund stays on record', function (): void {
    $cashAfterRefund = glBalance('1101');
    $advanceAfterRefund = glBalance('2111');

    $reversal = app(ReverseAdvanceRefund::class)($this->president, $this->refund, 'Refunded to the wrong member');

    expect($reversal->kind)->toBe(AdvanceEntryKind::Reversal)
        ->and($reversal->reverses_entry_id)->toBe($this->refund->id)
        ->and($reversal->delta_poisha->equals(Money::ofTaka('1000')))->toBeTrue()
        ->and($reversal->created_by)->toBe($this->president->id)
        ->and(app(AdvanceLedger::class)->balance($this->member->id)->equals(Money::ofTaka('3000')))->toBeTrue()
        ->and(glBalance('1101'))->toBe($cashAfterRefund - 100_000) // credit − debit: cash is debited back
        ->and(glBalance('2111'))->toBe($advanceAfterRefund + 100_000)
        ->and($reversal->journalEntry?->reverses_id)->toBe($this->refund->journal_entry_id)
        ->and($reversal->journalEntry?->reason)->toBe('Refunded to the wrong member')
        ->and(AdvanceLedgerEntry::query()->whereKey($this->refund->id)->exists())->toBeTrue();

    assertBooksTieOut();
});

it('needs someone other than whoever made the refund, with the permission', function (): void {
    expect(refundReversalRule(fn () => app(ReverseAdvanceRefund::class)($this->accountant, $this->refund, 'My own mistake')))->toBe('payments.errors.reverse_own_refund')
        ->and(fn () => app(ReverseAdvanceRefund::class)(userWithRole(Role::Cashier), $this->refund, 'Not allowed here'))->toThrow(AuthorizationException::class)
        ->and($this->refund->reversal()->exists())->toBeFalse();
});

it('cannot be reversed twice, and only refunds can be reversed', function (): void {
    app(ReverseAdvanceRefund::class)($this->president, $this->refund, 'Refunded to the wrong member');

    $surplus = AdvanceLedgerEntry::query()->where('kind', AdvanceEntryKind::PaymentSurplus)->firstOrFail();

    expect(refundReversalRule(fn () => app(ReverseAdvanceRefund::class)(userWithRole(Role::President), $this->refund, 'Once more please')))->toBe('payments.errors.refund_not_reversible')
        ->and(refundReversalRule(fn () => app(ReverseAdvanceRefund::class)($this->president, $surplus, 'Not a refund at all')))->toBe('payments.errors.refund_not_reversible')
        ->and(AdvanceLedgerEntry::query()->where('reverses_entry_id', $this->refund->id)->count())->toBe(1);
});

it('needs a reason', function (): void {
    expect(refundReversalRule(fn () => app(ReverseAdvanceRefund::class)($this->president, $this->refund, ' no ')))->toBe('journal.errors.reason_required');
});

it('is refused once the member has exited', function (): void {
    DB::table('members')->where('id', $this->member->id)->update(['status' => MemberStatus::Exited->value]);

    expect(refundReversalRule(fn () => app(ReverseAdvanceRefund::class)($this->president, $this->refund, 'Refunded to the wrong member')))->toBe('payments.errors.refund_reverse_after_exit')
        ->and($this->refund->reversal()->exists())->toBeFalse();
});

it('is reversed from the advance refunds page, and hidden from whoever made the refund', function (): void {
    $this->actingAs($this->accountant);

    Livewire::test(AdvanceRefunds::class)
        ->assertCanSeeTableRecords([$this->refund])
        ->assertActionHidden(TestAction::make('reverse')->table($this->refund));

    $this->actingAs($this->president);

    Livewire::test(AdvanceRefunds::class)
        ->callAction(TestAction::make('reverse')->table($this->refund), data: ['reason' => 'Refunded to the wrong member', 'confirm_text' => $this->member->member_no])
        ->assertHasNoActionErrors()
        ->assertActionHidden(TestAction::make('reverse')->table($this->refund));

    expect(app(AdvanceLedger::class)->balance($this->member->id)->equals(Money::ofTaka('3000')))->toBeTrue();
});
