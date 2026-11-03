<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Governance\Enums\MeetingType;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Settings\Data\FinancialSettingsData;
use App\Domain\Settings\Enums\FinancialSettingsStatus;
use App\Domain\Settings\Models\FinancialSettingsVersion;
use App\Support\Money\Bps;
use App\Support\Money\Money;

/**
 * T-19: the financial settings in force — the newest approved version, or the config defaults
 * until one is approved. Bound per request/job (scoped), so an approval applies to the next request
 * without a deploy; the approving request forgets its copy at once.
 */
final class FinancialSettings
{
    private ?FinancialSettingsVersion $version = null;

    private bool $loaded = false;

    public function forget(): void
    {
        $this->loaded = false;
        $this->version = null;
    }

    public function current(): FinancialSettingsData
    {
        $version = $this->currentVersion();

        return $version === null ? FinancialSettingsData::fromConfig() : $version->values();
    }

    public function currentVersion(): ?FinancialSettingsVersion
    {
        if (! $this->loaded) {
            $this->version = FinancialSettingsVersion::query()
                ->where('status', FinancialSettingsStatus::Approved)
                ->orderByDesc('decided_at')
                ->orderByDesc('id')
                ->first();
            $this->loaded = true;
        }

        return $this->version;
    }

    public function expensePresidentThreshold(): Money
    {
        return $this->current()->expensePresidentThreshold;
    }

    public function advanceRefundPresidentThreshold(): Money
    {
        return $this->current()->advanceRefundPresidentThreshold;
    }

    public function approvalReminderDays(): int
    {
        return $this->current()->approvalReminderDays;
    }

    public function quorum(MeetingType $type): Bps
    {
        $settings = $this->current();

        return match ($type) {
            MeetingType::Committee => $settings->quorumCommittee,
            MeetingType::General => $settings->quorumGeneral,
            MeetingType::SpecialGeneral => $settings->quorumSpecialGeneral,
        };
    }

    public function requiresResolution(ResolutionSubject $subject): bool
    {
        return $this->current()->requiresResolution($subject);
    }
}
