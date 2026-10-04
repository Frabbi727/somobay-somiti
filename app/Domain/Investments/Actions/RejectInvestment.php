<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class RejectInvestment
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Investment $investment, string $reason): Investment
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): Investment => DB::transaction(function () use ($actor, $investment, $reason): Investment {
            $locked = Investment::query()->whereKey($investment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvestmentStatus::Pending) {
                throw DomainRuleViolation::because('investments.errors.not_pending', ['number' => $locked->investment_no]);
            }

            Gate::forUser($actor)->authorize('reject', $locked);

            $locked->update(['status' => InvestmentStatus::Rejected, 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);

            return $locked;
        }, attempts: 3));
    }
}
