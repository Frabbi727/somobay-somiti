<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Enums\Area;
use App\Enums\Permission;
use App\Models\User;

/**
 * The secretary records an exit request; the president approves the settlement (§1.3), never the
 * person who requested it; the accountant or president pays it out.
 */
final class MemberExitPolicy
{
    public function viewAny(User $user): bool
    {
        return Area::Governance->allows($user);
    }

    public function view(User $user, MemberExit $exit): bool
    {
        return Area::Governance->allows($user);
    }

    public function create(User $user): bool
    {
        return $user->may(Permission::ExitsRequest);
    }

    public function approve(User $user, MemberExit $exit): bool
    {
        return $exit->status === ExitStatus::Requested
            && $exit->requested_by !== $user->id
            && $user->may(Permission::ExitsApprove);
    }

    public function cancel(User $user, MemberExit $exit): bool
    {
        return $exit->status === ExitStatus::Requested && $user->may(Permission::ExitsRequest);
    }

    public function pay(User $user, MemberExit $exit): bool
    {
        return $exit->status === ExitStatus::Approved && $user->may(Permission::ExitsPay);
    }

    /**
     * Undo a payout made by mistake: someone other than the person who paid it.
     */
    public function reversePayout(User $user, MemberExit $exit): bool
    {
        return $exit->status === ExitStatus::Paid
            && $exit->payout_journal_entry_id !== null
            && $exit->paid_by !== $user->id
            && $user->may(Permission::ExitsReversePayout);
    }

    public function update(User $user, MemberExit $exit): bool
    {
        return false;
    }

    public function delete(User $user, MemberExit $exit): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, MemberExit $exit): bool
    {
        return false;
    }
}
