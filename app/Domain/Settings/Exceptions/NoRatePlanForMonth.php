<?php

declare(strict_types=1);

namespace App\Domain\Settings\Exceptions;

use App\Support\Time\YearMonth;
use RuntimeException;

final class NoRatePlanForMonth extends RuntimeException
{
    public static function for(YearMonth $month): self
    {
        $message = trans('rates.errors.no_plan_for_month', ['month' => (string) $month]);

        return new self(is_string($message) ? $message : 'No approved rate plan for '.$month);
    }
}
