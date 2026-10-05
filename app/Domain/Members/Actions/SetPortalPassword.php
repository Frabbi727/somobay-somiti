<?php

declare(strict_types=1);

namespace App\Domain\Members\Actions;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The secretary or president sets (or resets) a member's portal password, for societies that do
 * not use SMS sign-in codes. The member can change it from their profile afterwards.
 */
final class SetPortalPassword
{
    public const int MIN_LENGTH = 6;

    public function __construct(
        private readonly PortalAccounts $accounts,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Member $member, string $password): User
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw DomainRuleViolation::because('members.errors.portal_password_short', ['min' => self::MIN_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): User => DB::transaction(function () use ($actor, $member, $password): User {
            $locked = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('setPortalPassword', $locked);

            if ($locked->status === MemberStatus::Exited) {
                throw DomainRuleViolation::because('members.errors.exited');
            }

            $user = $this->accounts->forMember($locked);
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete(); // signed out of the app everywhere

            activity('members')->performedOn($locked)->log('portal password set');

            return $user;
        }, attempts: 3));
    }
}
