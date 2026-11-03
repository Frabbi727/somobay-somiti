<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Data\FinancialSettingsData;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Money\Bps;

/**
 * What a set of financial settings must satisfy. The year-end bounds are the legal ones in
 * config/somiti.php (s.34); they are not settings themselves.
 */
final class FinancialSettingsRules
{
    public const int MAX_REMINDER_DAYS = 30;

    public function assertValid(FinancialSettingsData $data): void
    {
        if (! $data->expensePresidentThreshold->isPositive() || ! $data->advanceRefundPresidentThreshold->isPositive()) {
            throw DomainRuleViolation::because('financial_settings.errors.threshold_positive');
        }

        if ($data->approvalReminderDays < 0 || $data->approvalReminderDays > self::MAX_REMINDER_DAYS) {
            throw DomainRuleViolation::because('financial_settings.errors.reminder_days', ['max' => self::MAX_REMINDER_DAYS]);
        }

        foreach ([$data->quorumCommittee, $data->quorumGeneral, $data->quorumSpecialGeneral] as $quorum) {
            if ($quorum->value < 1 || $quorum->value > 10_000) {
                throw DomainRuleViolation::because('financial_settings.errors.quorum_range');
            }
        }

        $rates = ['reserve' => $data->reserve, 'development_fund' => $data->developmentFund, 'bad_debt_fund' => $data->badDebtFund, 'other_funds' => $data->otherFunds];

        foreach ($rates as $key => $rate) {
            $min = (int) config("somiti.year_end.{$key}.min");
            $max = (int) config("somiti.year_end.{$key}.max");

            if ($key === 'bad_debt_fund' && $data->financingSociety) {
                $min = max($min, (int) config('somiti.year_end.bad_debt_fund.financing_min'));
            }

            if ($rate->value < $min || $rate->value > $max) {
                throw DomainRuleViolation::because('year_end.errors.rate_bounds', [
                    'fund' => __('year_end.fund.'.$key),
                    'min' => Bps::of($min)->format(app()->getLocale()),
                    'max' => Bps::of($max)->format(app()->getLocale()),
                ]);
            }
        }
    }
}
