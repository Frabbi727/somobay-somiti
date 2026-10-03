<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Actions;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Waives an unpaid late fee with a recorded reason. (Waiving a fee that was already paid moves the
 * money to the member's advance; that arrives with collections in Phase 5.)
 */
final class WaiveLateFee
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Due $due, string $reason): Due
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw DomainRuleViolation::because('dues.errors.reason_required');
        }

        return $this->causer->withCauser($actor, fn (): Due => DB::transaction(function () use ($actor, $due, $reason): Due {
            $locked = Due::query()->whereKey($due->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('waive', $locked);

            if ($locked->type !== DueType::LateFee || $locked->status !== DueStatus::Open) {
                throw DomainRuleViolation::because('dues.errors.not_waivable');
            }

            if ($locked->paid_poisha->isPositive()) {
                throw DomainRuleViolation::because('dues.errors.waive_paid');
            }

            $locked->forceFill([
                'status' => DueStatus::Waived,
                'note' => trim($reason),
                'closed_at' => CarbonImmutable::now(),
            ])->save();

            activity('dues')->performedOn($locked)->causedBy($actor)->event('late_fee_waived')
                ->withProperties(['reason' => trim($reason), 'amount_poisha' => $locked->amount_poisha->poisha])
                ->log('late_fee_waived');

            return $locked;
        }, attempts: 3));
    }
}
