<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\ReadOnlyForStaff;

/**
 * Rows are added through InvestmentPolicy::setExpectedReturn and never changed.
 */
final class InvestmentReturnExpectationPolicy
{
    use ReadOnlyForStaff;
}
