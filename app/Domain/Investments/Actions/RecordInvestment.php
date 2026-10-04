<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Accounting\Services\Accounts;
use App\Domain\Investments\Data\InvestmentData;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Records a proposed investment (maker). Nothing is posted until the president approves it.
 */
final class RecordInvestment
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, InvestmentData $data): Investment
    {
        Gate::forUser($actor)->authorize('create', Investment::class);

        $existing = Investment::query()->where('idempotency_key', $data->idempotencyKey)->first();

        if ($existing !== null) {
            if ($existing->type !== $data->type || ! $existing->principal_poisha->equals($data->principal)) {
                throw DomainRuleViolation::because('investments.errors.idempotency_conflict');
            }

            return $existing;
        }

        $this->assertValid($data);

        return $this->causer->withCauser($actor, fn (): Investment => DB::transaction(function () use ($actor, $data): Investment {
            $number = (int) DB::scalar("SELECT nextval('investment_no_seq')");

            return Investment::query()->create([
                'investment_no' => sprintf('INV-%04d', $number),
                'type' => $data->type,
                'account_id' => $this->accounts->byCode($data->type->accountCode())->id,
                'institution' => $data->institution,
                'instrument_no' => $data->instrumentNo,
                'principal_poisha' => $data->principal,
                'funded_from' => $data->fundedFrom,
                'invested_on' => $data->investedOn->toDateString(),
                'matures_on' => $data->maturesOn?->toDateString(),
                'expected_rate_bps' => $data->expectedRate?->value,
                'notes' => $data->notes,
                'attachment_path' => $data->attachmentPath,
                'resolution_id' => $data->resolutionId,
                'status' => InvestmentStatus::Pending,
                'idempotency_key' => $data->idempotencyKey,
                'recorded_by' => $actor->id,
            ]);
        }, attempts: 3));
    }

    private function assertValid(InvestmentData $data): void
    {
        if (mb_strlen($data->institution) < 2) {
            throw DomainRuleViolation::because('investments.errors.institution_required');
        }

        if (! $data->principal->isPositive()) {
            throw DomainRuleViolation::because('investments.errors.amount_positive');
        }

        if ($data->investedOn->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay())) {
            throw DomainRuleViolation::because('investments.errors.future_date');
        }

        if ($data->maturesOn !== null && ! $data->maturesOn->isAfter($data->investedOn)) {
            throw DomainRuleViolation::because('investments.errors.maturity');
        }
    }
}
