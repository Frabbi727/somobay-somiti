<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Settings\Enums\FinancialSettingsStatus;
use App\Domain\Settings\Models\FinancialSettingsVersion;
use App\Domain\Settings\Services\FinancialSettings;
use App\Domain\Settings\Services\FinancialSettingsRules;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\YearEnd;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * T-19 (checker): puts proposed financial settings in force. Year-end choices cannot change while
 * a prepared year-end waits for approval, or its figures would differ between preparing and posting.
 */
final class ApproveFinancialSettings
{
    public function __construct(
        private readonly CauserResolver $causer,
        private readonly WorkflowNotifier $notifier,
        private readonly FinancialSettings $settings,
        private readonly FinancialSettingsRules $rules,
    ) {}

    public function __invoke(User $actor, FinancialSettingsVersion $version): FinancialSettingsVersion
    {
        $approved = $this->causer->withCauser($actor, fn (): FinancialSettingsVersion => DB::transaction(function () use ($actor, $version): FinancialSettingsVersion {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['financial_settings']);
            $locked = FinancialSettingsVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== FinancialSettingsStatus::Pending) {
                throw DomainRuleViolation::because('financial_settings.errors.not_pending');
            }

            Gate::forUser($actor)->authorize('approve', $locked);

            $proposed = $locked->values();
            $this->rules->assertValid($proposed);

            if ($proposed->changesYearEnd($this->settings->current()) && YearEnd::query()->where('status', YearEndStatus::Draft)->exists()) {
                throw DomainRuleViolation::because('financial_settings.errors.year_end_in_progress');
            }

            $locked->update([
                'status' => FinancialSettingsStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now(),
            ]);

            $this->settings->forget();

            return $locked;
        }, attempts: 3));

        $this->notifier->outcome($approved, 'approved', [$approved->proposed_by]);

        return $approved;
    }
}
