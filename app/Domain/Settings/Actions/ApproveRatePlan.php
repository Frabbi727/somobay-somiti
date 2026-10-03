<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Contracts\RatePlanUsage;
use App\Domain\Settings\Enums\ApprovalDecision;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RatePlanRules;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Records one approval. When both the president and the secretary have approved the current
 * submission, the plan becomes approved; an approved plan for the same month that no due
 * uses yet is superseded by it.
 */
final class ApproveRatePlan
{
    /** @var list<Role> */
    public const array REQUIRED_ROLES = [Role::President, Role::Secretary];

    public function __construct(
        private readonly RatePlanRules $rules,
        private readonly RatePlanUsage $usage,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, RatePlan $plan, ?string $comment = null): RatePlan
    {
        return $this->causer->withCauser($actor, fn (): RatePlan => DB::transaction(function () use ($actor, $plan, $comment): RatePlan {
            $locked = RatePlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('approve', $locked);

            if ($locked->status !== RatePlanStatus::PendingApproval) {
                throw DomainRuleViolation::because('rates.errors.not_pending', ['code' => $locked->code]);
            }

            if ($locked->hasDecided($actor)) {
                throw DomainRuleViolation::because('rates.errors.already_decided', ['code' => $locked->code]);
            }

            $locked->approvals()->create([
                'submission_no' => $locked->submission_no,
                'user_id' => $actor->id,
                'role' => self::approvingRole($actor),
                'decision' => ApprovalDecision::Approve,
                'comment' => $comment === null || trim($comment) === '' ? null : trim($comment),
            ]);

            $approved = $locked->approvedRoles();

            if (array_diff(array_map(fn (Role $role): string => $role->value, self::REQUIRED_ROLES), array_map(fn (Role $role): string => $role->value, $approved)) === []) {
                $this->finalise($locked);
            }

            return $locked;
        }, attempts: 3));
    }

    /**
     * The committee role under which this user approves (president outranks secretary).
     */
    public static function approvingRole(User $user): Role
    {
        foreach (self::REQUIRED_ROLES as $role) {
            if ($user->hasAnyOf($role)) {
                return $role;
            }
        }

        throw DomainRuleViolation::because('rates.errors.not_approver');
    }

    private function finalise(RatePlan $plan): void
    {
        $this->rules->assertMonthOpen($plan);

        $current = RatePlan::query()
            ->where('status', RatePlanStatus::Approved)
            ->where('effective_from', $plan->effective_from->toDateString())
            ->lockForUpdate()
            ->first();

        if ($current !== null) {
            if ($this->usage->isReferenced($current)) {
                throw DomainRuleViolation::because('rates.errors.month_in_use', ['code' => $current->code]);
            }

            $current->forceFill(['status' => RatePlanStatus::Superseded])->save();
            $plan->supersedes_id = $current->id;
        }

        $plan->forceFill([
            'status' => RatePlanStatus::Approved,
            'approved_at' => CarbonImmutable::now(),
        ])->save();
    }
}
