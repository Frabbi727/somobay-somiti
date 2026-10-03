<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Enums\ApprovalDecision;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Sends a submitted plan back to draft with a recorded reason.
 */
final class RejectRatePlan
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, RatePlan $plan, string $comment): RatePlan
    {
        if (mb_strlen(trim($comment)) < 5) {
            throw DomainRuleViolation::because('rates.errors.comment_required');
        }

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
                'role' => ApproveRatePlan::approvingRole($actor),
                'decision' => ApprovalDecision::Reject,
                'comment' => trim($comment),
            ]);

            $locked->forceFill(['status' => RatePlanStatus::Draft])->save();

            return $locked;
        }, attempts: 3));
    }
}
