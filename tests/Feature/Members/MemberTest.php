<?php

declare(strict_types=1);

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Actions\DeactivateMember;
use App\Domain\Members\Actions\ReactivateMember;
use App\Domain\Members\Actions\UpdateMember;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Nominee;
use App\Domain\Settings\Actions\CancelRatePlan;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Support\Contact\MobileNumber;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->plan = approvedPlan('2026-07', '500');
});

function memberRuleKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('normalises Bangladeshi mobile numbers', function (string $input, ?string $expected): void {
    expect(MobileNumber::normalize($input))->toBe($expected);
})->with([
    ['01712345678', '01712345678'],
    ['01712-345678', '01712345678'],
    ['+880 1712 345678', '01712345678'],
    ['8801712345678', '01712345678'],
    ['০১৭১২৩৪৫৬৭৮', '01712345678'],
    ['01212345678', null],
    ['0171234567', null],
    ['', null],
]);

it('onboards a member with nominees, a share lot and a registration fee due', function (): void {
    $member = onboard(2, '2026-07', [
        'mobile' => '+880 1712-345678',
        'nid' => '১২৩৪৫৬৭৮৯০',
        'nominees' => [
            ['name' => 'Karim', 'relation' => 'Son', 'share_percent' => '60'],
            ['name' => 'Salma', 'relation' => 'Wife', 'share_percent' => '40'],
        ],
    ]);

    expect($member->member_no)->toMatch('/^M-\d{4,}$/')
        ->and($member->mobile)->toBe('01712345678')
        ->and($member->nid)->toBe('1234567890')
        ->and($member->status)->toBe(MemberStatus::Active)
        ->and($member->nominees()->pluck('share_bps')->all())->toBe([6000, 4000])
        ->and($member->sharesIn(YearMonth::of(2026, 7)))->toBe(2)
        ->and($member->sharesIn(YearMonth::of(2026, 6)))->toBe(0);

    $due = Due::query()->where('member_id', $member->id)->sole();

    expect($due->type)->toBe(DueType::Registration)
        ->and($due->amount_poisha->poisha)->toBe(20000)
        ->and($due->outstanding_poisha->poisha)->toBe(20000)
        ->and((string) $due->month)->toBe('2026-07')
        ->and($due->due_date->toDateString())->toBe('2026-07-10')
        ->and($due->rate_plan_id)->toBe($this->plan->id)
        ->and($due->snapshot['registration_fee_per_share_poisha'])->toBe(10000)
        ->and($due->status)->toBe(DueStatus::Open);
});

it('numbers members in sequence', function (): void {
    $first = onboard();
    $second = onboard();

    expect((int) substr($second->member_no, 2))->toBe((int) substr($first->member_no, 2) + 1);
});

it('validates member details', function (array $overrides, string $key): void {
    onboard(overrides: ['mobile' => '01799999999', 'nid' => '1111111111']);

    expect(memberRuleKey(fn () => onboard(overrides: $overrides)))->toBe($key);
})->with([
    'bad mobile' => [['mobile' => '01212345678'], 'members.errors.mobile_format'],
    'taken mobile' => [['mobile' => '01799-999999'], 'members.errors.mobile_taken'],
    'bad nid' => [['nid' => '12345'], 'members.errors.nid_format'],
    'taken nid' => [['nid' => '1111111111'], 'members.errors.nid_taken'],
    'missing english name' => [['name_en' => ' '], 'members.errors.names_required'],
    'nominees under 100%' => [['nominees' => [['name' => 'A', 'relation' => 'Son', 'share_percent' => '60']]], 'members.errors.nominee_total'],
    'nominee without relation' => [['nominees' => [['name' => 'A', 'relation' => '', 'share_percent' => '100']]], 'members.errors.nominee_incomplete'],
    'nominee mobile with 12 digits' => [['nominees' => [['name' => 'A', 'relation' => 'Wife', 'share_percent' => '100', 'mobile' => '019877765644']]], 'members.errors.nominee_mobile_format'],
]);

it('needs an approved rate plan for the first month', function (): void {
    expect(memberRuleKey(fn () => onboard(1, '2026-06')))->toBe('members.errors.no_rate_plan')
        ->and(Member::query()->count())->toBe(0);
});

it('skips the registration due when the fee is zero', function (): void {
    approvedPlan('2026-08', '500', ['registration_fee_per_share_poisha' => Money::zero()]);

    $member = onboard(3, '2026-08');

    expect(registrationDues($member))->toBe([])
        ->and($member->sharesIn(YearMonth::of(2026, 8)))->toBe(3);
});

it('updates details and replaces nominees while keeping the old ones in history', function (): void {
    $member = onboard(overrides: ['nominees' => [['name' => 'Karim', 'relation' => 'Son', 'share_percent' => '100']]]);

    app(UpdateMember::class)(userWithRole(Role::Secretary), $member, memberData([
        'mobile' => $member->mobile,
        'name_en' => 'Rahim Uddin Mia',
        'nominees' => [['name' => 'Salma', 'relation' => 'Wife', 'share_percent' => '100']],
    ]));

    expect($member->fresh()?->name_en)->toBe('Rahim Uddin Mia')
        ->and($member->nominees()->pluck('name')->all())->toBe(['Salma'])
        ->and(Nominee::withTrashed()->where('member_id', $member->id)->count())->toBe(2);
});

it('deactivates with a reason and reactivates', function (): void {
    $member = onboard();
    $secretary = userWithRole(Role::Secretary);

    expect(memberRuleKey(fn () => app(DeactivateMember::class)($secretary, $member, 'no')))->toBe('members.errors.reason_required');

    $inactive = app(DeactivateMember::class)($secretary, $member, 'Moved abroad for a year');

    expect($inactive->status)->toBe(MemberStatus::Inactive)
        ->and($inactive->deactivation_reason)->toBe('Moved abroad for a year')
        ->and(app(ReactivateMember::class)($secretary, $inactive)->status)->toBe(MemberStatus::Active);
});

it('lets only the secretary and president manage members', function (Role $role): void {
    app(CreateMember::class)(userWithRole($role), memberData(), 1, YearMonth::of(2026, 7));
})->throws(AuthorizationException::class)->with([Role::Cashier, Role::Accountant, Role::Auditor, Role::SuperAdmin]);

it('keeps a due amount fixed in the model and the database', function (): void {
    $due = Due::query()->where('member_id', onboard()->id)->sole();

    expect(fn () => $due->forceFill(['amount_poisha' => Money::ofTaka('1')])->save())->toThrow(ImmutableRecord::class)
        ->and(fn () => $due->delete())->toThrow(ImmutableRecord::class);

    DB::statement('UPDATE dues SET amount_poisha = 1 WHERE id = ?', [$due->id]);
})->throws(QueryException::class, 'immutable');

it('keeps paid within the amount at the database level', function (): void {
    $due = Due::query()->where('member_id', onboard()->id)->sole();

    DB::statement('UPDATE dues SET paid_poisha = amount_poisha + 1 WHERE id = ?', [$due->id]);
})->throws(QueryException::class, 'dues_paid_within_amount');

it('marks a rate plan as used once a due references it', function (): void {
    onboard();

    expect(memberRuleKey(fn () => app(CancelRatePlan::class)(userWithRole(Role::President), $this->plan, 'Wrong fee')))
        ->toBe('rates.errors.month_in_use');
});
