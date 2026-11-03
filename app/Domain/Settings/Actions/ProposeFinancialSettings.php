<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Settings\Data\FinancialSettingsData;
use App\Domain\Settings\Enums\FinancialSettingsStatus;
use App\Domain\Settings\Models\FinancialSettingsVersion;
use App\Domain\Settings\Services\FinancialSettings;
use App\Domain\Settings\Services\FinancialSettingsRules;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * T-19 (maker): proposes a complete new set of financial settings with a reason. Nothing changes
 * until someone else approves it; only one proposal waits at a time.
 */
final class ProposeFinancialSettings
{
    public const int MIN_REASON_LENGTH = 5;

    public function __construct(
        private readonly CauserResolver $causer,
        private readonly WorkflowNotifier $notifier,
        private readonly FinancialSettings $settings,
        private readonly FinancialSettingsRules $rules,
    ) {}

    public function __invoke(User $actor, FinancialSettingsData $data, string $reason, string $idempotencyKey): FinancialSettingsVersion
    {
        Gate::forUser($actor)->authorize('create', FinancialSettingsVersion::class);

        return $this->causer->withCauser($actor, fn (): FinancialSettingsVersion => DB::transaction(function () use ($actor, $data, $reason, $idempotencyKey): FinancialSettingsVersion {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['financial_settings']);

            $existing = FinancialSettingsVersion::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->values()->changesFrom($data) !== []) {
                    throw DomainRuleViolation::because('financial_settings.errors.idempotency_conflict');
                }

                return $existing;
            }

            $reason = trim($reason);

            if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
                throw DomainRuleViolation::because('financial_settings.errors.reason_required', ['min' => self::MIN_REASON_LENGTH]);
            }

            if (FinancialSettingsVersion::query()->where('status', FinancialSettingsStatus::Pending)->exists()) {
                throw DomainRuleViolation::because('financial_settings.errors.pending_exists');
            }

            $this->rules->assertValid($data);

            if ($data->changesFrom($this->settings->current()) === []) {
                throw DomainRuleViolation::because('financial_settings.errors.no_change');
            }

            $version = FinancialSettingsVersion::query()->create([
                'settings' => $data->toStored(),
                'reason' => $reason,
                'status' => FinancialSettingsStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'proposed_by' => $actor->id,
            ]);

            $this->notifier->waiting($version);

            return $version;
        }, attempts: 3));
    }
}
