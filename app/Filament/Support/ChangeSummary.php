<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use BackedEnum;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;

/**
 * Builds the old → new table shown in tier-2 confirmations (SOMITI_SPEC.md §7.2).
 */
final class ChangeSummary
{
    /**
     * @param  array<string, string>  $labels  attribute => translated label, in display order
     * @param  array<string, mixed>  $old  attribute => value before (empty for a create)
     * @param  array<string, mixed>  $new  attribute => value after
     * @return list<array{label: string, old: string, new: string}>
     */
    public static function rows(array $labels, array $old, array $new, bool $onlyChanged = true): array
    {
        $rows = [];

        foreach ($labels as $key => $label) {
            $before = self::format($old[$key] ?? null);
            $after = self::format($new[$key] ?? null);

            if ($onlyChanged && $old !== [] && $before === $after) {
                continue;
            }

            $rows[] = ['label' => $label, 'old' => $before, 'new' => $after];
        }

        return $rows;
    }

    public static function format(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => __($value ? 'common.yes' : 'common.no'),
            $value instanceof HasLabel => self::labelText($value->getLabel()),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof Money => Display::money($value),
            $value instanceof YearMonth => Display::yearMonth($value),
            $value instanceof DateTimeInterface => Display::date($value),
            is_scalar($value) => (string) $value,
            default => '—',
        };
    }

    /**
     * @param  list<mixed>  $rows  rows built by rows()
     */
    public static function view(array $rows, bool $showOld = true): View
    {
        return view('filament.confirmations.change-summary', [
            'rows' => $rows,
            'showOld' => $showOld,
        ]);
    }

    private static function labelText(string|Htmlable|null $label): string
    {
        return match (true) {
            $label === null => '—',
            $label instanceof Htmlable => strip_tags($label->toHtml()),
            default => $label,
        };
    }
}
