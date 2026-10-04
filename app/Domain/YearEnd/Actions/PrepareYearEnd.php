<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Actions;

use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Enums\PeriodStatus;
use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Models\Expense;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\FundTransfer;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\YearEnd;
use App\Domain\YearEnd\Services\YearEndCalculator;
use App\Filament\Support\Display;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W8 steps 1–4: with every month but the last locked and nothing awaiting approval, computes the
 * year-end and stores it as a draft for the president and accountant. Preparing again replaces
 * the draft and its approvals.
 */
final class PrepareYearEnd
{
    public function __construct(
        private readonly YearEndCalculator $calculator,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, FiscalYear $fiscalYear, AppropriationRates $rates, ?int $resolutionId = null): YearEnd
    {
        Gate::forUser($actor)->authorize('create', YearEnd::class);

        return $this->causer->withCauser($actor, fn (): YearEnd => DB::transaction(function () use ($actor, $fiscalYear, $rates, $resolutionId): YearEnd {
            $year = FiscalYear::query()->whereKey($fiscalYear->getKey())->lockForUpdate()->firstOrFail();

            $this->assertReady($year);

            $existing = YearEnd::query()->where('fiscal_year_id', $year->id)->lockForUpdate()->first();

            if ($existing !== null && $existing->status === YearEndStatus::Posted) {
                throw DomainRuleViolation::because('year_end.errors.already_posted', ['code' => $year->code]);
            }

            $existing?->delete();

            $figures = $this->calculator->calculate($year, $rates);

            return YearEnd::query()->create([
                'fiscal_year_id' => $year->id,
                'status' => YearEndStatus::Draft,
                'net_profit_poisha' => $figures->netProfit,
                'prior_loss_poisha' => $figures->priorLoss,
                'loss_offset_poisha' => $figures->lossOffset,
                'reserve_bps' => $rates->reserve->value,
                'development_fund_bps' => $rates->developmentFund->value,
                'bad_debt_fund_bps' => $rates->badDebtFund->value,
                'other_funds_bps' => $rates->otherFunds->value,
                'appropriation' => $figures->appropriationPoisha(),
                'dividend_pool_poisha' => $figures->dividendPool,
                'total_share_months' => $figures->totalShareMonths(),
                'fingerprint' => $figures->fingerprint(),
                'resolution_id' => $resolutionId,
                'prepared_by' => $actor->id,
            ]);
        }, attempts: 3));
    }

    /**
     * The months before the last are locked (no more postings), the last is still open for the
     * closing entries, and nothing dated in the year is waiting for approval.
     */
    public function assertReady(FiscalYear $year): void
    {
        if (! $year->isOpen()) {
            throw DomainRuleViolation::because('accounting.errors.fiscal_year_closed', ['code' => $year->code]);
        }

        $periods = $year->periods()->orderBy('sequence')->get();
        $last = $periods->last();
        $open = $periods->filter(fn ($period): bool => $period->status === PeriodStatus::Open && $period->isNot($last));

        if ($open->isNotEmpty()) {
            throw DomainRuleViolation::because('year_end.errors.periods_open', [
                'months' => $open->map(fn ($period): string => Display::yearMonth($period->month))->implode(', '),
            ]);
        }

        if ($last === null || $last->status !== PeriodStatus::Open) {
            throw DomainRuleViolation::because('year_end.errors.last_period_locked');
        }

        $until = $year->ends_on->toDateString();
        $pending = Payment::query()->where('status', PaymentStatus::Pending)->where('received_on', '<=', $until)->count()
            + Expense::query()->where('status', ExpenseStatus::Pending)->where('spent_on', '<=', $until)->count()
            + FundTransfer::query()->where('status', TransferStatus::Pending)->where('transferred_on', '<=', $until)->count()
            + Investment::query()->where('status', InvestmentStatus::Pending)->where('invested_on', '<=', $until)->count();

        if ($pending > 0) {
            throw DomainRuleViolation::because('year_end.errors.pending_items', ['count' => Display::digits($pending)]);
        }
    }
}
