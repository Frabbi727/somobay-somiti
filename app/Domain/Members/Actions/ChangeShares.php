<?php

declare(strict_types=1);

namespace App\Domain\Members\Actions;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Services\ShareChanger;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Adds (positive delta) or removes (negative delta) shares from a month onwards (BR-1, BR-4).
 */
final class ChangeShares
{
    public function __construct(
        private readonly ShareChanger $shares,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Member $member, int $delta, YearMonth $effectiveFrom, ?string $reason = null): Member
    {
        return $this->causer->withCauser($actor, fn (): Member => DB::transaction(function () use ($actor, $member, $delta, $effectiveFrom, $reason): Member {
            $locked = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('changeShares', $locked);

            if (! $locked->isActive()) {
                throw DomainRuleViolation::because('members.errors.not_active', ['member' => $locked->member_no]);
            }

            match (true) {
                $delta > 0 => $this->shares->increase($actor, $locked, $delta, $effectiveFrom, $reason),
                $delta < 0 => $this->shares->decrease($actor, $locked, -$delta, $effectiveFrom, $reason),
                default => throw DomainRuleViolation::because('members.errors.shares_positive'),
            };

            return $locked;
        }, attempts: 3));
    }
}
