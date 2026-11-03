<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Services\RequiredResolutions;
use App\Domain\Investments\Data\InvestmentTopUpData;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentTopUp;
use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Records more money for an active investment (maker). Nothing is paid out until someone else
 * approves it.
 */
final class RecordInvestmentTopUp
{
    public function __construct(
        private readonly CauserResolver $causer,
        private readonly WorkflowNotifier $notifier,
        private readonly RequiredResolutions $resolutions,
    ) {}

    public function __invoke(User $actor, Investment $investment, InvestmentTopUpData $data): InvestmentTopUp
    {
        $existing = InvestmentTopUp::query()->where('idempotency_key', $data->idempotencyKey)->first();

        if ($existing !== null) {
            if ($existing->investment_id !== $investment->id || ! $existing->amount_poisha->equals($data->amount)) {
                throw DomainRuleViolation::because('investments.errors.idempotency_conflict');
            }

            return $existing;
        }

        return $this->causer->withCauser($actor, fn (): InvestmentTopUp => DB::transaction(function () use ($actor, $investment, $data): InvestmentTopUp {
            $locked = Investment::query()->whereKey($investment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvestmentStatus::Active) {
                throw DomainRuleViolation::because('investments.errors.top_up_not_active', ['number' => $locked->investment_no]);
            }

            Gate::forUser($actor)->authorize('addTopUp', $locked);
            $this->assertValid($locked, $data);
            $this->resolutions->assertNotUsedElsewhere(ResolutionSubject::Investment, $data->resolutionId);

            $topUp = $locked->topUps()->create([
                'amount_poisha' => $data->amount,
                'funded_from' => $data->fundedFrom,
                'invested_on' => $data->investedOn->toDateString(),
                'notes' => $data->notes,
                'resolution_id' => $data->resolutionId,
                'status' => InvestmentTopUpStatus::Pending,
                'idempotency_key' => $data->idempotencyKey,
                'recorded_by' => $actor->id,
            ]);

            $this->notifier->waiting($topUp);

            return $topUp;
        }, attempts: 3));
    }

    private function assertValid(Investment $investment, InvestmentTopUpData $data): void
    {
        if (! $data->amount->isPositive()) {
            throw DomainRuleViolation::because('investments.errors.amount_positive');
        }

        if ($data->investedOn->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay())) {
            throw DomainRuleViolation::because('investments.errors.future_date');
        }

        if ($data->investedOn->isBefore($investment->invested_on)) {
            throw DomainRuleViolation::because('investments.errors.top_up_date', ['date' => $investment->invested_on->toDateString()]);
        }
    }
}
