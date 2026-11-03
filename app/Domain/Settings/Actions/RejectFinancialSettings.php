<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Settings\Enums\FinancialSettingsStatus;
use App\Domain\Settings\Models\FinancialSettingsVersion;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * T-19 (checker): turns down proposed financial settings with a reason; nothing changes.
 */
final class RejectFinancialSettings
{
    public const int MIN_REASON_LENGTH = 5;

    public function __construct(
        private readonly CauserResolver $causer,
        private readonly WorkflowNotifier $notifier,
    ) {}

    public function __invoke(User $actor, FinancialSettingsVersion $version, string $reason): FinancialSettingsVersion
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('financial_settings.errors.reason_required', ['min' => self::MIN_REASON_LENGTH]);
        }

        $rejected = $this->causer->withCauser($actor, fn (): FinancialSettingsVersion => DB::transaction(function () use ($actor, $version, $reason): FinancialSettingsVersion {
            $locked = FinancialSettingsVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== FinancialSettingsStatus::Pending) {
                throw DomainRuleViolation::because('financial_settings.errors.not_pending');
            }

            Gate::forUser($actor)->authorize('reject', $locked);

            $locked->update([
                'status' => FinancialSettingsStatus::Rejected,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now(),
                'rejection_reason' => $reason,
            ]);

            return $locked;
        }, attempts: 3));

        $this->notifier->outcome($rejected, 'rejected', [$rejected->proposed_by]);

        return $rejected;
    }
}
