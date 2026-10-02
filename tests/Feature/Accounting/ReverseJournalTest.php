<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Actions\SetAccountActive;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->fiscalYear = app(OpenFiscalYear::class)($this->accountant, 2026);
    $this->original = app(PostJournal::class)($this->accountant, new JournalEntryData(
        VoucherType::Receipt,
        CarbonImmutable::parse('2026-07-10'),
        'Collection',
        [
            JournalLineData::debit(account('1101'), Money::ofTaka('1020')),
            JournalLineData::credit(account('2101'), Money::ofTaka('1000'), memberId: 3, memo: 'deposit'),
            JournalLineData::credit(account('4111'), Money::ofTaka('20')),
        ],
    ));
    $this->reverse = app(ReverseJournal::class);
});

/**
 * Net balance (debit − credit) per account across all posted lines.
 *
 * @return array<int, int>
 */
function netByAccount(): array
{
    return JournalLine::query()
        ->selectRaw('account_id, SUM(debit_poisha) - SUM(credit_poisha) AS net')
        ->groupBy('account_id')
        ->pluck('net', 'account_id')
        ->map(fn ($net): int => (int) $net)
        ->all();
}

it('posts a mirror-image JV that nets every account to zero', function (): void {
    $reversal = ($this->reverse)($this->accountant, $this->original, 'Wrong member', CarbonImmutable::parse('2026-08-02'));

    expect($reversal->voucher_no)->toBe('JV-2026-27-000001')
        ->and($reversal->reverses_id)->toBe($this->original->id)
        ->and($reversal->reason)->toBe('Wrong member')
        ->and($reversal->narration)->toBe('↺ RV-2026-27-000001: Collection')
        ->and($this->original->fresh()?->isReversed())->toBeTrue()
        ->and($this->original->reversal?->id)->toBe($reversal->id)
        ->and($reversal->lines->map(fn ($line): array => [$line->account_id, $line->member_id, $line->debit_poisha->poisha, $line->credit_poisha->poisha, $line->memo])->all())
        ->toBe([
            [account('1101')->id, null, 0, 102000, null],
            [account('2101')->id, 3, 100000, 0, 'deposit'],
            [account('4111')->id, null, 2000, 0, null],
        ])
        ->and(array_filter(netByAccount()))->toBe([]);
});

it('records the reversal and its reason in the activity log', function (): void {
    ($this->reverse)($this->accountant, $this->original, 'Duplicate entry');

    $activity = Activity::query()->where('event', 'reversed')->sole();

    expect($activity->subject_id)->toBe($this->original->id)
        ->and($activity->causer_id)->toBe($this->accountant->id)
        ->and($activity->properties['reason'])->toBe('Duplicate entry');
});

it('reverses into the current open month even when the original month is locked', function (): void {
    app(LockPeriod::class)($this->accountant, $this->fiscalYear->periods()->firstOrFail());

    $reversal = ($this->reverse)($this->accountant, $this->original, 'Late correction', CarbonImmutable::parse('2026-08-05'));

    expect((string) $reversal->period->month)->toBe('2026-08');
});

it('can reverse postings to an account deactivated since', function (): void {
    app(SetAccountActive::class)($this->accountant, account('4111'), false);

    expect(($this->reverse)($this->accountant, $this->original, 'Service charge waived', CarbonImmutable::parse('2026-07-20'))->lines)->toHaveCount(3);
});

it('refuses to reverse twice, to reverse a reversal, or without a reason', function (): void {
    $reversal = ($this->reverse)($this->accountant, $this->original, 'First time', CarbonImmutable::parse('2026-07-20'));

    expect(fn () => ($this->reverse)($this->accountant, $this->original->fresh(), 'Again please'))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->reverse)($this->accountant, $reversal, 'Undo the undo'))->toThrow(AuthorizationException::class);
});

it('requires a meaningful reason', function (): void {
    ($this->reverse)($this->accountant, $this->original, ' ok ');
})->throws(DomainRuleViolation::class);

it('rejects a second reversal even when the policy check is bypassed', function (): void {
    ($this->reverse)($this->accountant, $this->original, 'First time', CarbonImmutable::parse('2026-07-20'));

    Gate::before(fn (): bool => true);

    ($this->reverse)($this->accountant, $this->original->fresh(), 'Second time', CarbonImmutable::parse('2026-07-21'));
})->throws(DomainRuleViolation::class);

it('lets only accountants and the president reverse', function (Role $role): void {
    ($this->reverse)(userWithRole($role), $this->original, 'Not allowed');
})->throws(AuthorizationException::class)->with([Role::Cashier, Role::SuperAdmin, Role::Auditor, Role::Secretary]);
