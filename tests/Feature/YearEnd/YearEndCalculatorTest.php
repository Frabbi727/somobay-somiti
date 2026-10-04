<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Members\Actions\ChangeShares;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Domain\YearEnd\Data\YearEndFigures;
use App\Domain\YearEnd\Services\ShareMonths;
use App\Domain\YearEnd\Services\YearEndCalculator;
use App\Enums\Role;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

/*
| Phase 11 (P11.S1): BR-20 statutory appropriation, s.34(4) loss offset, BR-21 share-month dividends.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->fy = app(OpenFiscalYear::class)($this->accountant, 2026);
    approvedPlan('2026-07', '500');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function earn(string $taka, string $date = '2026-09-15', string $income = '4111'): void
{
    app(PostJournal::class)(userWithRole(Role::Accountant), simpleEntry('1101', $income, $taka, $date));
}

function spend(string $taka, string $date = '2026-09-20', string $expense = '5101'): void
{
    app(PostJournal::class)(userWithRole(Role::Accountant), simpleEntry($expense, '1101', $taka, $date));
}

function figures(FiscalYear $fy, array $rates = []): YearEndFigures
{
    return app(YearEndCalculator::class)->calculate($fy, AppropriationRates::fromForm($rates));
}

function yearEndRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('appropriates net profit by statute and leaves the remainder as the dividend pool, exactly', function (): void {
    onboard(2, '2026-07');
    earn('10000.33');
    earn('1500', income: '4121');
    spend('2000.12');

    $figures = figures($this->fy);

    // Net profit ৳9,500.21 → reserve 15% = 1,425.0315 → 1,425.03; CDF 3% = 285.0063 → 285.01.
    expect($figures->netProfit->poisha)->toBe(950021)
        ->and($figures->appropriationPoisha())->toBe(['reserve' => 142503, 'development_fund' => 28501, 'bad_debt_fund' => 0, 'other_funds' => 0])
        ->and($figures->dividendPool->poisha)->toBe(950021 - 142503 - 28501)
        ->and($figures->dividendPool->plus(Money::sum($figures->appropriation))->plus($figures->lossOffset)->equals($figures->netProfit))->toBeTrue();
});

it('first sets half the profit against a deficit brought forward (s.34(4))', function (): void {
    onboard(1, '2026-07');
    app(OpenFiscalYear::class)($this->accountant, 2025);
    // ৳3,000 deficit in 3901 at the end of the previous year …
    app(PostJournal::class)($this->accountant, simpleEntry('3901', '3101', '3000', '2026-06-30'));
    // … while a deficit posted inside this year is not "prior".
    app(PostJournal::class)($this->accountant, simpleEntry('3901', '3101', '500', '2026-07-02'));
    earn('10000');

    $figures = figures($this->fy);

    expect($figures->priorLoss->poisha)->toBe(300000)
        ->and($figures->lossOffset->poisha)->toBe(300000) // min(50% × ৳10,000, ৳3,000)
        ->and($figures->dividendPool->poisha)->toBe(1000000 - 300000 - 150000 - 30000);
});

it('caps the offset at half the profit', function (): void {
    onboard(1, '2026-07');
    app(OpenFiscalYear::class)($this->accountant, 2025);
    app(PostJournal::class)($this->accountant, simpleEntry('3901', '3101', '8000', '2026-06-30'));
    earn('10000');

    expect(figures($this->fy)->lossOffset->poisha)->toBe(500000);
});

it('enforces the legal bounds on each rate', function (array $rates, string $key): void {
    onboard(1, '2026-07');
    earn('1000');

    expect(yearEndRule(fn () => figures($this->fy, $rates)))->toBe($key);
})->with([
    'reserve below 15%' => [['reserve' => '14'], 'year_end.errors.rate_bounds'],
    'development fund not 3%' => [['development_fund' => '4'], 'year_end.errors.rate_bounds'],
    'other funds above 10%' => [['other_funds' => '10.5'], 'year_end.errors.rate_bounds'],
]);

it('requires the 10% bad-debt fund only for financing societies', function (): void {
    onboard(1, '2026-07');
    earn('1000');

    expect(figures($this->fy)->appropriationPoisha()['bad_debt_fund'])->toBe(0);

    config(['somiti.year_end.financing_society' => true]);

    expect(figures($this->fy)->appropriationPoisha()['bad_debt_fund'])->toBe(10000)
        ->and(yearEndRule(fn () => figures($this->fy, ['bad_debt_fund' => '5'])))->toBe('year_end.errors.rate_bounds');
});

it('counts share-months from the share timeline', function (): void {
    $allYear = onboard(2, '2026-07');           // 2 × 12 = 24
    $fromOctober = onboard(1, '2026-10');       // 1 × 9  = 9
    $grows = onboard(1, '2026-07');             // 1 × 6 + 3 × 6 = 24
    app(ChangeShares::class)(userWithRole(Role::Secretary), $grows, 2, YearMonth::of(2027, 1), 'more shares');

    expect(app(ShareMonths::class)->forYear(2026))->toBe([$allYear->id => 24, $fromOctober->id => 9, $grows->id => 24]);
});

it('splits the pool by share-months with exact totals and ties to the lower member', function (): void {
    $a = onboard(1, '2026-07');
    $b = onboard(1, '2026-07');
    $c = onboard(1, '2026-07');
    earn('1.21'); // pool after 15% + 3%: 121 − 18 − 4 = 99 poisha

    $figures = figures($this->fy);

    expect($figures->dividendPool->poisha)->toBe(99)
        ->and(array_map(fn (Money $m): int => $m->poisha, $figures->dividends))->toBe([$a->id => 33, $b->id => 33, $c->id => 33]);

    earn('0.01'); // 122 − 18 − 4 = 100 → one extra poisha to the lowest member id

    expect(array_map(fn (Money $m): int => $m->poisha, figures($this->fy)->dividends))->toBe([$a->id => 34, $b->id => 33, $c->id => 33]);
});

it('appropriates nothing in a loss year', function (): void {
    onboard(1, '2026-07');
    earn('100');
    spend('250');

    $figures = figures($this->fy);

    expect($figures->netProfit->poisha)->toBe(-15000)
        ->and($figures->isProfit())->toBeFalse()
        ->and($figures->dividends)->toBe([])
        ->and(array_sum($figures->appropriationPoisha()))->toBe(0);
});

it('refuses appropriations larger than the profit', function (): void {
    onboard(1, '2026-07');
    earn('1000');
    config(['somiti.year_end.reserve.max' => 10000]);

    expect(yearEndRule(fn () => figures($this->fy, ['reserve' => '90', 'other_funds' => '10'])))->toBe('year_end.errors.over_appropriated');
});
