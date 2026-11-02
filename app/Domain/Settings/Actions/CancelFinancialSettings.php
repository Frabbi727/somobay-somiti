<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Enums\FinancialSettingsStatus;
use App\Domain\Settings\Models\FinancialSettingsVersion;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * T-19: the proposer withdraws a proposal that is still waiting.
 */
final class CancelFinancialSettings
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, FinancialSettingsVersion $version): FinancialSettingsVersion
    {
        return $this->causer->withCauser($actor, fn (): FinancialSettingsVersion => DB::transaction(function () use ($actor, $version): FinancialSettingsVersion {
            $locked = FinancialSettingsVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== FinancialSettingsStatus::Pending) {
                throw DomainRuleViolation::because('financial_settings.errors.not_pending');
            }

            Gate::forUser($actor)->authorize('cancel', $locked);

            $locked->update(['status' => FinancialSettingsStatus::Cancelled, 'decided_at' => CarbonImmutable::now()]);

            return $locked;
        }, attempts: 3));
    }
}
