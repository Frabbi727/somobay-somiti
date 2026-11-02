<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Investments\Data\ExpectedReturnData;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\ReturnFrequency;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentReturnExpectation;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * T-12: records what an active investment is expected to pay from a date (or that it stopped).
 * Adds a row; never edits one. Tracking only — nothing is posted.
 */
final class SetExpectedReturn
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Investment $investment, ExpectedReturnData $data): InvestmentReturnExpectation
    {
        return $this->causer->withCauser($actor, fn (): InvestmentReturnExpectation => DB::transaction(function () use ($actor, $investment, $data): InvestmentReturnExpectation {
            $locked = Investment::query()->whereKey($investment->getKey())->lockForUpdate()->firstOrFail();

            $existing = InvestmentReturnExpectation::query()->where('idempotency_key', $data->idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->investment_id !== $locked->id || ! $existing->amount_poisha->equals($data->amount)) {
                    throw DomainRuleViolation::because('investments.errors.idempotency_conflict');
                }

                return $existing;
            }

            if ($locked->status !== InvestmentStatus::Active) {
                throw DomainRuleViolation::because('investments.returns.errors.not_active', ['number' => $locked->investment_no]);
            }

            Gate::forUser($actor)->authorize('setExpectedReturn', $locked);
            $this->assertValid($locked, $data);

            return $locked->returnExpectations()->create([
                'amount_poisha' => $data->amount,
                'frequency' => $data->frequency,
                'starts_on' => $data->startsOn->toDateString(),
                'notes' => $data->notes,
                'idempotency_key' => $data->idempotencyKey,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        }, attempts: 3));
    }

    private function assertValid(Investment $investment, ExpectedReturnData $data): void
    {
        if ($data->frequency !== ReturnFrequency::None && ! $data->amount->isPositive()) {
            throw DomainRuleViolation::because('investments.errors.amount_positive');
        }

        if ($data->startsOn->isBefore($investment->invested_on)) {
            throw DomainRuleViolation::because('investments.returns.errors.before_investment', ['date' => $investment->invested_on->toDateString()]);
        }

        if ($data->frequency === ReturnFrequency::None && ! $investment->returnExpectations()->exists()) {
            throw DomainRuleViolation::because('investments.returns.errors.nothing_to_stop');
        }
    }
}
