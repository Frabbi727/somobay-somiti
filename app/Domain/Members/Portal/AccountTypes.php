<?php

declare(strict_types=1);

namespace App\Domain\Members\Portal;

use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Models\User;

/**
 * Member-side logins are either members (not exited) or people with an open registration.
 * Anything else — staff, exited members, rejected registrations — has no member-side access.
 */
final class AccountTypes
{
    public function __construct(private readonly PortalAccounts $portal) {}

    public function of(User $user): ?AccountType
    {
        if ($user->isStaff()) {
            return null;
        }

        if ($this->portal->activeMemberOf($user) !== null) {
            return AccountType::Member;
        }

        return $this->openApplicationOf($user) === null ? null : AccountType::Applicant;
    }

    public function openApplicationOf(User $user): ?MemberApplication
    {
        return MemberApplication::query()
            ->where('user_id', $user->id)
            ->whereIn('status', MemberApplicationStatus::openValues())
            ->first();
    }
}
