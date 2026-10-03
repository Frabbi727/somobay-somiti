<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Services\StaffUserRules;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Deactivates (they can no longer sign in) or reactivates a staff account.
 */
final class SetStaffUserActive
{
    public function __construct(
        private readonly StaffUserRules $rules,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, User $user, bool $active): User
    {
        return $this->causer->withCauser($actor, fn (): User => DB::transaction(function () use ($actor, $user, $active): User {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);

            if (! $active && $locked->is($actor)) {
                throw DomainRuleViolation::because('users.errors.cannot_deactivate_self');
            }

            if (! $active && $locked->hasAnyOf(Role::SuperAdmin)) {
                $this->rules->assertSuperAdminRemains($locked);
            }

            if ($locked->isActive() === $active) {
                return $locked;
            }

            $locked->forceFill(['deactivated_at' => $active ? null : CarbonImmutable::now()])->save();

            activity('users')->performedOn($locked)->log($active ? 'reactivated' : 'deactivated');

            return $locked;
        }, attempts: 3));
    }
}
