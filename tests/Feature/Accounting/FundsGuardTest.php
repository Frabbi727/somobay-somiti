<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Services\FundsGuard;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/*
| Known gap #12: a back-dated payout must not make the cash balance negative on its own date or
| on any later date, even when today's balance would cover it.
*/

beforeEach(function (): void {
    travelTo('2026-08-20');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    app(OpenFiscalYear::class)($this->accountant, 2026);

    // ৳20,000 in hand on 1 August.
    app(PostJournal::class)($this->accountant, simpleEntry('1101', '3101', '20000', '2026-08-01'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function fundsRule(string $taka, string $on): ?string
{
    try {
        DB::transaction(fn () => app(FundsGuard::class)->assertCovers(account('1101'), Money::ofTaka($taka), CarbonImmutable::parse($on)));
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('refuses a payout dated before the money arrived', function (): void {
    expect(fundsRule('1500', '2026-07-20'))->toBe('accounting.errors.insufficient_funds')
        ->and(fundsRule('1500', '2026-08-01'))->toBeNull();
});

it('refuses a back-dated payout that later spending has already used up', function (): void {
    app(PostJournal::class)($this->accountant, simpleEntry('5101', '1101', '18000', '2026-08-15'));

    // 20,000 on hand on 5 August, but only 2,000 left after the 15 August expense.
    expect(fundsRule('5000', '2026-08-05'))->toBe('accounting.errors.insufficient_funds')
        ->and(fundsRule('2000', '2026-08-05'))->toBeNull()
        ->and(fundsRule('2000.01', '2026-08-05'))->toBe('accounting.errors.insufficient_funds');
});

it('counts money received on the payout date', function (): void {
    app(PostJournal::class)($this->accountant, simpleEntry('1101', '3101', '3000', '2026-07-25'));

    expect(fundsRule('3000', '2026-07-25'))->toBeNull()
        ->and(fundsRule('3000.01', '2026-07-25'))->toBe('accounting.errors.insufficient_funds');
});
