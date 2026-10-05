<?php

declare(strict_types=1);

use App\Domain\Members\Actions\ChangeShares;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;

/*
| The member's share position: shares held this month, every share change, and this month's rates
| from the approved rate plan (none when no plan covers the month).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));
    approvedPlan('2026-07', '500');
    approvedPlan('2026-10', '600');
    $this->member = onboard(2, '2026-07');
    $this->token = memberToken($this->member);
});

it('shows current shares, their history and this month\'s rates from the approved plan', function (): void {
    app(ChangeShares::class)(userWithRole(Role::Secretary), $this->member, 1, YearMonth::parse('2026-11'), 'Bought one more');

    $data = $this->withToken($this->token)->getJson('/api/v1/shares/overview')->assertOk()->json('data');

    expect($data['current_shares'])->toBe(2)
        ->and($data['history'][0])->toMatchArray(['shares' => 1, 'shares_after' => 3, 'effective_from' => '2026-11', 'reason' => 'Bought one more'])
        ->and($data['history'][1])->toMatchArray(['shares_after' => 2, 'effective_from' => '2026-07'])
        ->and($data['rates']['effective_from'])->toBe('2026-10')
        ->and($data['rates']['share_unit']['poisha'])->toBe(60000)
        ->and($data['rates'])->toHaveKeys(['service_charge_per_share', 'registration_fee_per_share', 'due_day', 'grace_days', 'late_fee'])
        ->and($data['rates']['late_fee'])->toHaveKeys(['mode', 'fixed', 'percent', 'cap', 'frequency']);
});

it('shows no rates when no plan covers this month', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-05 10:00'));

    $this->withToken($this->token)->getJson('/api/v1/shares/overview')->assertOk()->assertJsonPath('data.rates', null);
});
