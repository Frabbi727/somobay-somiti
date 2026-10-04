<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Actions;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Services\RequiredResolutions;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Domain\YearEnd\Models\YearEnd;
use App\Domain\YearEnd\Services\YearEndCalculator;
use App\Domain\YearEnd\Services\YearEndPoster;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Bps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W8 step 6: the president and an accountant each approve (T3). If the books moved since the draft,
 * the approval is refused until it is prepared again. The second approval posts the year-end.
 */
final class ApproveYearEnd
{
    public function __construct(
        private readonly YearEndCalculator $calculator,
        private readonly RequiredResolutions $resolutions,
        private readonly YearEndPoster $poster,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, YearEnd $yearEnd): YearEnd
    {
        return $this->causer->withCauser($actor, fn (): YearEnd => DB::transaction(function () use ($actor, $yearEnd): YearEnd {
            $locked = YearEnd::query()->whereKey($yearEnd->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('approve', $locked);

            $fiscalYear = FiscalYear::query()->whereKey($locked->fiscal_year_id)->lockForUpdate()->firstOrFail();
            $this->resolutions->assertSatisfied(ResolutionSubject::YearEnd, $locked->resolution_id);

            $figures = $this->calculator->calculate($fiscalYear, new AppropriationRates(
                Bps::of($locked->reserve_bps),
                Bps::of($locked->development_fund_bps),
                Bps::of($locked->bad_debt_fund_bps),
                Bps::of($locked->other_funds_bps),
            ));

            if ($figures->fingerprint() !== $locked->fingerprint) {
                throw DomainRuleViolation::because('year_end.errors.stale');
            }

            $now = CarbonImmutable::now();

            if ($actor->hasAnyOf(Role::President) && $locked->president_approved_by === null) {
                $locked->update(['president_approved_by' => $actor->id, 'president_approved_at' => $now]);
            } else {
                $locked->update(['accountant_approved_by' => $actor->id, 'accountant_approved_at' => $now]);
            }

            if ($locked->isFullyApproved()) {
                $this->poster->post($actor, $locked, $fiscalYear, $figures);
            }

            return $locked;
        }, attempts: 3));
    }
}
