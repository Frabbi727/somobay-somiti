<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Services\Reconciliation;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Exits\Actions\ApproveExit;
use App\Domain\Exits\Actions\PayExit;
use App\Domain\Exits\Actions\RequestExit;
use App\Domain\Exits\Actions\ReverseExitPayout;
use App\Domain\Exits\Enums\ExitReason;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Exits\Models\MemberExitPayoutReversal;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Filament\Resources\MemberExits\Pages\ViewMemberExit;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Undoing an exit payout made by mistake: the money comes back to 2301, the exit waits to be paid
| again, the settlement is untouched and the original payout stays on record.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    approvedPlan('2026-07', '500');

    $member = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($member, '610'); // registration 100 + July 500 + 10
    generateMonth('2026-08');
    travelTo('2026-08-05');
    receivePayment($member, '510');
    travelTo('2026-09-05');

    $exit = app(RequestExit::class)(userWithRole(Role::Secretary), $member, ExitReason::Voluntary, 'Moving to another district', YearMonth::parse('2026-08'), Money::zero());
    $approved = app(ApproveExit::class)($this->president, $exit);
    $this->exit = app(PayExit::class)($this->accountant, $approved, PaymentMethod::Cash);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function exitPayoutRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('reverses a payout: the money returns to 2301 and the exit waits to be paid again', function (): void {
    $net = $this->exit->net_poisha;
    $cashAfterPayout = glBalance('1101');

    $reversed = app(ReverseExitPayout::class)($this->president, $this->exit, 'Paid to the wrong person');

    expect($reversed->status)->toBe(ExitStatus::Approved)
        ->and($reversed->paid_at)->toBeNull()
        ->and($reversed->net_poisha?->equals($net))->toBeTrue()
        ->and(glBalance('2301'))->toBe($net?->poisha)
        ->and(glBalance('1101'))->toBe($cashAfterPayout - (int) $net?->poisha)
        ->and($reversed->payouts()->count())->toBe(1);

    $record = MemberExitPayoutReversal::query()->sole();

    expect($record->reason)->toBe('Paid to the wrong person')
        ->and($record->paid_by)->toBe($this->accountant->id)
        ->and($record->amount_poisha->equals($net))->toBeTrue();

    assertBooksTieOut();

    // Paid again, correctly.
    $repaid = app(PayExit::class)($this->accountant, $reversed, PaymentMethod::Cash);

    expect($repaid->status)->toBe(ExitStatus::Paid)
        ->and(glBalance('2301'))->toBe(0)
        ->and($repaid->payouts()->count())->toBe(2);

    assertBooksTieOut();
});

it('keeps the 2301 register tied to the ledger on every date', function (): void {
    travelTo('2026-09-10');
    app(ReverseExitPayout::class)($this->president, $this->exit, 'Paid to the wrong person');

    foreach (['2026-09-05', '2026-09-10'] as $day) {
        $check = collect(app(Reconciliation::class)->controlVsSubledger(CarbonImmutable::parse($day)))
            ->firstWhere(fn ($check): bool => $check->account->code === '2301');

        expect($check?->isReconciled())->toBeTrue();
    }
});

it('needs someone other than the payer, with the permission', function (): void {
    expect(exitPayoutRule(fn () => app(ReverseExitPayout::class)($this->accountant, $this->exit, 'My own mistake')))->toBe('exits.errors.reverse_own_payout')
        ->and(fn () => app(ReverseExitPayout::class)(userWithRole(Role::Secretary), $this->exit, 'Not allowed here'))->toThrow(AuthorizationException::class)
        ->and(MemberExit::query()->findOrFail($this->exit->id)->status)->toBe(ExitStatus::Paid);
});

it('cannot be reversed twice, and the database refuses to un-pay an exit without a reversal', function (): void {
    expect(fn () => DB::transaction(fn () => DB::table('member_exits')->where('id', $this->exit->id)->update([
        'status' => 'approved', 'paid_at' => null, 'paid_by' => null, 'payout_journal_entry_id' => null,
    ])))->toThrow(QueryException::class);

    $reversed = app(ReverseExitPayout::class)($this->president, $this->exit, 'Paid to the wrong person');

    expect(exitPayoutRule(fn () => app(ReverseExitPayout::class)(userWithRole(Role::President), $reversed, 'Once more please')))->toBe('exits.errors.nothing_to_reverse');
});

it('is reversed from the exit page', function (): void {
    $this->actingAs($this->president);

    Livewire::test(ViewMemberExit::class, ['record' => $this->exit->getKey()])
        ->callAction('reversePayout', data: ['reason' => 'Paid to the wrong person', 'confirm_text' => $this->exit->member->member_no])
        ->assertHasNoActionErrors();

    expect(MemberExit::query()->findOrFail($this->exit->id)->status)->toBe(ExitStatus::Approved);
});
