<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Members\Terms\Enums\TermsStatus;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Enums\Permission;
use App\Models\User;

/**
 * Staff read every version; "publish the somiti rules" holders write the draft and publish it.
 * A published version is never edited or deleted.
 */
final class MembershipTermsPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, MembershipTerms $terms): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->may(Permission::TermsPublish);
    }

    public function update(User $user, MembershipTerms $terms): bool
    {
        return $terms->status === TermsStatus::Draft && $user->may(Permission::TermsPublish);
    }

    public function publish(User $user, MembershipTerms $terms): bool
    {
        return $terms->status === TermsStatus::Draft && $user->may(Permission::TermsPublish);
    }

    public function delete(User $user, MembershipTerms $terms): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, MembershipTerms $terms): bool
    {
        return false;
    }

    public function forceDelete(User $user, MembershipTerms $terms): bool
    {
        return false;
    }
}
