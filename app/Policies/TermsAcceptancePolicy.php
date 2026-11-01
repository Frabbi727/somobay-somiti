<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Members\Models\Member;
use App\Models\User;
use App\Policies\Concerns\ReadOnlyForStaff;

final class TermsAcceptancePolicy
{
    use ReadOnlyForStaff;

    /**
     * Members accept the rules for themselves only.
     */
    public function accept(User $user, Member $member): bool
    {
        return $member->user_id !== null && $member->user_id === $user->id;
    }
}
