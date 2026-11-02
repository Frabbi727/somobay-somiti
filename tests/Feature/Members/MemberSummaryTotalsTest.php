<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Members\Portal\MemberSummary;
use App\Enums\Role;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

/*
| The member's home screen totals: billed, paid, waived, this month's due, earlier months' due and
| the monthly deposit for their shares, all read from the dues so they always add up.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500', ['service_charge_per_share_poisha' => Money::zero(), 'registration_fee_per_share_poisha' => Money::zero()]);
    $this->member = onboard(2, '2026-07');

    foreach (['2026-07', '2026-08', '2026-09'] as $month) {
        generateMonth($month);
    }

    travelTo('2026-09-15');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('splits what is owed into this month and earlier months, and the totals add up', function (): void {
    receivePayment($this->member, '1500');

    $summary = app(MemberSummary::class)->for($this->member);

    expect($summary['billed']->poisha)->toBe(300000)
        ->and($summary['paid']->poisha)->toBe(150000)
        ->and($summary['waived']->poisha)->toBe(0)
        ->and($summary['due_earlier']->poisha)->toBe(50000)
        ->and($summary['due_this_month']->poisha)->toBe(100000)
        ->and($summary['outstanding']->poisha)->toBe(150000)
        ->and($summary['billed']->minus($summary['paid'])->minus($summary['waived'])->equals($summary['outstanding']))->toBeTrue()
        ->and($summary['monthly_deposit']?->poisha)->toBe(100000);
});

it('sends the same totals to the app', function (): void {
    $summary = app(MemberSummary::class)->for($this->member);

    $this->withToken(memberToken($this->member))->getJson('/api/v1/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('data.billed.poisha', $summary['billed']->poisha)
        ->assertJsonPath('data.paid.poisha', 0)
        ->assertJsonPath('data.due_this_month.poisha', 100000)
        ->assertJsonPath('data.due_earlier.poisha', 200000)
        ->assertJsonPath('data.monthly_deposit.poisha', 100000);
});
