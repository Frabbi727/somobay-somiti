<?php

declare(strict_types=1);

namespace App\Reports\Definitions;

use App\Domain\Accounting\Services\FiscalCalendar;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;

/**
 * Shared filter fields and parsing for report definitions.
 */
trait ReadsFilters
{
    protected function dateField(string $name, string $label): DatePicker
    {
        return DatePicker::make($name)->label($label)->native(false)->required()->live();
    }

    protected function monthField(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->type('month')->regex('/^\d{4}-\d{2}$/')->required()->live(onBlur: true);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function date(array $filters, string $key): ?CarbonImmutable
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, YearMonth::TIMEZONE) : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function month(array $filters, string $key): ?YearMonth
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? YearMonth::parse($value) : null;
    }

    protected function today(): string
    {
        return CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString();
    }

    protected function fiscalYearStart(): string
    {
        return FiscalCalendar::startsOn(FiscalCalendar::startYearFor(YearMonth::current()))->toDateString();
    }
}
