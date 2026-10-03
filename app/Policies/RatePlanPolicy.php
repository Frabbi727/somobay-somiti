<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Enums\Role;
use App\Models\User;

/**
 * Drafting is for the secretary, accountant or super admin; approval needs the president
 * and the secretary, never the plan's own author (maker-checker, G4).
 */
final class RatePlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, RatePlan $plan): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyOf(Role::SuperAdmin, Role::Secretary, Role::Accountant);
    }

    public function update(User $user, RatePlan $plan): bool
    {
        return $plan->status === RatePlanStatus::Draft && ! $plan->trashed() && $this->create($user);
    }

    public function submit(User $user, RatePlan $plan): bool
    {
        return $this->update($user, $plan);
    }

    public function approve(User $user, RatePlan $plan): bool
    {
        return $plan->status === RatePlanStatus::PendingApproval
            && $plan->created_by !== $user->id
            && $user->hasAnyOf(Role::President, Role::Secretary)
            && ! $plan->hasDecided($user);
    }

    public function cancel(User $user, RatePlan $plan): bool
    {
        return in_array($plan->status, [RatePlanStatus::Draft, RatePlanStatus::PendingApproval, RatePlanStatus::Approved], true)
            && $user->hasAnyOf(Role::President);
    }

    public function duplicate(User $user, RatePlan $plan): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, RatePlan $plan): bool
    {
        return $plan->status === RatePlanStatus::Draft && $plan->submission_no === 0 && $this->create($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, RatePlan $plan): bool
    {
        return false;
    }

    public function forceDelete(User $user, RatePlan $plan): bool
    {
        return false;
    }
}
