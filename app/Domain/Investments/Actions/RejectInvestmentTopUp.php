<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Domain\Investments\Models\InvestmentTopUp;
use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class RejectInvestmentTopUp
{
    public function __construct(
        private readonly CauserResolver $causer,
        private readonly WorkflowNotifier $notifier,
    ) {}

    public function __invoke(User $actor, InvestmentTopUp $topUp, string $reason): InvestmentTopUp
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): InvestmentTopUp => DB::transaction(function () use ($actor, $topUp, $reason): InvestmentTopUp {
            $locked = InvestmentTopUp::query()->with('investment')->whereKey($topUp->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvestmentTopUpStatus::Pending) {
                throw DomainRuleViolation::because('investments.errors.top_up_not_pending', ['number' => $locked->investment->investment_no]);
            }

            Gate::forUser($actor)->authorize('reject', $locked);

            $locked->update(['status' => InvestmentTopUpStatus::Rejected, 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);

            $this->notifier->outcome($locked, 'rejected', [$locked->recorded_by]);

            return $locked;
        }, attempts: 3));
    }
}
