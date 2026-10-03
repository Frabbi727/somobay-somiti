<?php

declare(strict_types=1);

use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Actions\ChangeShares;
use App\Domain\Members\Actions\DeactivateMember;
use App\Domain\Members\Enums\ShareChangeType;
use App\Domain\Members\Models\ShareLot;
use App\Domain\Settings\Contracts\GeneratedMonths;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    $this->secretary = userWithRole(Role::Secretary);
});

function changeShares(int $delta, string $from, $member, ?User $actor = null): void
{
    app(ChangeShares::class)($actor ?? userWithRole(Role::Secretary), $member, $delta, YearMonth::parse($from), 'test');
}

function shareRuleKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

/**
 * @return array<string, int> month => shares, from the member's timeline
 */
function timeline($member): array
{
    return $member->shareSnapshots()->get()->mapWithKeys(fn ($row): array => [(string) $row->effective_from => $row->shares])->all();
}

it('charges each lot the registration fee of its own month (P3 acceptance)', function (): void {
    approvedPlan('2026-07', '500', ['registration_fee_per_share_poisha' => Money::ofTaka('100')]);
    $member = onboard(2, '2026-07');

    // The committee raises the fee from September.
    approvedPlan('2026-09', '500', ['registration_fee_per_share_poisha' => Money::ofTaka('150')]);

    changeShares(1, '2026-10', $member);

    expect(registrationDues($member))->toBe([20000, 15000])
        ->and(Due::query()->where('member_id', $member->id)->orderBy('id')->first()?->snapshot['registration_fee_per_share_poisha'])->toBe(10000)
        ->and(timeline($member))->toBe(['2026-07' => 2, '2026-10' => 3])
        ->and($member->shareTransactions()->pluck('shares_after')->all())->toBe([2, 3]);
});

it('charges existing shares the difference when the plan says so', function (): void {
    approvedPlan('2026-07', '500', ['registration_fee_per_share_poisha' => Money::ofTaka('100')]);
    $holder = onboard(2, '2026-07');
    $other = onboard(1, '2026-08');
    $inactive = onboard(4, '2026-07');
    app(DeactivateMember::class)($this->secretary, $inactive, 'Paused by committee');

    approvedPlan('2026-09', '500', [
        'registration_fee_per_share_poisha' => Money::ofTaka('150'),
        'registration_fee_on_rate_increase' => 'difference',
    ]);

    expect(registrationDues($holder))->toBe([20000, 10000])
        ->and(registrationDues($other))->toBe([10000, 5000])
        ->and(registrationDues($inactive))->toBe([40000])
        ->and(Due::query()->where('member_id', $holder->id)->orderByDesc('id')->first()?->note)->toBe('Registration fee top-up');

    // Shares acquired after the increase pay the new fee in full and get no top-up.
    changeShares(1, '2026-10', $holder);
    expect(registrationDues($holder))->toBe([20000, 10000, 15000]);
});

it('does not top up when the fee falls or the policy is none', function (): void {
    approvedPlan('2026-07', '500', ['registration_fee_per_share_poisha' => Money::ofTaka('100')]);
    $member = onboard(2, '2026-07');

    approvedPlan('2026-09', '500', ['registration_fee_per_share_poisha' => Money::ofTaka('150')]);
    approvedPlan('2026-11', '500', ['registration_fee_per_share_poisha' => Money::ofTaka('120'), 'registration_fee_on_rate_increase' => 'difference']);

    expect(registrationDues($member))->toBe([20000]);
});

it('removes shares oldest lot first and splits a lot when needed', function (): void {
    approvedPlan('2026-07');
    $member = onboard(3, '2026-07');
    changeShares(2, '2026-09', $member);

    changeShares(-4, '2026-12', $member);

    $lots = ShareLot::query()->where('member_id', $member->id)->orderBy('id')->get();

    expect($lots->map(fn (ShareLot $lot): array => [$lot->shares, (string) $lot->effective_from, $lot->ended_from === null ? null : (string) $lot->ended_from])->all())
        ->toBe([
            [3, '2026-07', '2026-12'],
            [2, '2026-09', '2026-12'],
            [1, '2026-12', null],
        ])
        ->and($lots->last()?->continues_lot_id)->toBe($lots[1]->id)
        ->and(timeline($member))->toBe(['2026-07' => 3, '2026-09' => 5, '2026-12' => 1])
        ->and(count(registrationDues($member)))->toBe(2)
        ->and($member->shareTransactions()->reorder('id', 'desc')->first()?->type)->toBe(ShareChangeType::Decrease);
});

it('keeps at least one share; leaving is an exit', function (): void {
    approvedPlan('2026-07');
    $member = onboard(2, '2026-07');

    expect(shareRuleKey(fn () => changeShares(-2, '2026-09', $member)))->toBe('members.errors.too_few_shares_left');
});

it('only extends share history forwards', function (): void {
    approvedPlan('2026-07');
    $member = onboard(1, '2026-07');
    changeShares(1, '2026-10', $member);

    expect(shareRuleKey(fn () => changeShares(1, '2026-09', $member)))->toBe('members.errors.before_last_change');
});

it('refuses to change shares in a month that already has dues (BR-1)', function (): void {
    approvedPlan('2026-07');
    $member = onboard(1, '2026-07');

    app()->instance(GeneratedMonths::class, new class implements GeneratedMonths
    {
        public function latest(): ?YearMonth
        {
            return YearMonth::of(2026, 9);
        }
    });

    expect(shareRuleKey(fn () => changeShares(1, '2026-09', $member)))->toBe('members.errors.month_generated');

    changeShares(1, '2026-10', $member);
    expect($member->sharesIn(YearMonth::of(2026, 10)))->toBe(2);
});

it('cannot reduce shares bought in the same month', function (): void {
    approvedPlan('2026-07');
    $member = onboard(1, '2026-07');
    changeShares(1, '2026-10', $member);

    expect(shareRuleKey(fn () => changeShares(-1, '2026-10', $member)))->toBeNull()
        ->and(shareRuleKey(fn () => changeShares(-1, '2026-11', $member)))->toBe('members.errors.too_few_shares_left');
});

it('does not change shares of an inactive member', function (): void {
    approvedPlan('2026-07');
    $member = onboard(1, '2026-07');
    app(DeactivateMember::class)($this->secretary, $member, 'Paused by committee');

    changeShares(1, '2026-10', $member->fresh());
})->throws(AuthorizationException::class);
