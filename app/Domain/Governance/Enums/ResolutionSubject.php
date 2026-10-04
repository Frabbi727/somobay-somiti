<?php

declare(strict_types=1);

namespace App\Domain\Governance\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a resolution decides. Subjects listed in somiti.require_resolution_for must have a
 * passed resolution linked before the matching approval completes.
 */
enum ResolutionSubject: string implements HasLabel
{
    case RatePlan = 'rate_plan';
    case AdvancePolicy = 'advance_policy';
    case Investment = 'investment';
    case YearEnd = 'year_end';
    case Exit = 'exit';
    case Expense = 'expense';
    case Bylaws = 'bylaws';
    case Other = 'other';

    public function isRequired(): bool
    {
        return in_array($this->value, (array) config('somiti.require_resolution_for'), true);
    }

    public function getLabel(): string
    {
        return __('governance.subject.'.$this->value);
    }
}
