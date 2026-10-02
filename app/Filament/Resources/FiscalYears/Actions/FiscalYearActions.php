<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears\Actions;

use App\Domain\Accounting\Actions\CloseFiscalYear;
use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\UnlockPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\Period;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Time\YearMonth;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class FiscalYearActions
{
    use ConfirmsWithTier;

    /**
     * Opens the year after the latest one, or the current year when none exist yet.
     */
    public static function openNext(): Action
    {
        return self::open('openNextFiscalYear', fn (): int => self::nextStartYear());
    }

    /**
     * Opens the year before the earliest one, for entering historical books.
     */
    public static function openPrevious(): Action
    {
        return self::open('openPreviousFiscalYear', fn (): int => self::previousStartYear())
            ->color('gray')
            ->visible(fn (): bool => FiscalYear::query()->exists());
    }

    public static function close(): Action
    {
        $action = Action::make('close')
            ->label(__('accounting.actions.close_fiscal_year'))
            ->tooltip(__('accounting.actions.close_fiscal_year'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('danger')
            ->authorize('close')
            ->action(function (FiscalYear $record): void {
                DomainActionRunner::run(fn (User $actor): FiscalYear => app(CloseFiscalYear::class)($actor, $record));

                Notification::make()
                    ->title(__('accounting.notifications.fiscal_year_closed', ['code' => $record->code]))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (FiscalYear $record): string => __('accounting.actions.close_fiscal_year_heading', ['code' => $record->code]),
            expected: fn (FiscalYear $record): string => $record->code,
            submitLabel: fn (FiscalYear $record): string => __('accounting.actions.close_fiscal_year_submit', ['code' => $record->code]),
            description: __('accounting.actions.close_fiscal_year_description'),
        );
    }

    public static function lockPeriod(): Action
    {
        $action = Action::make('lock')
            ->label(__('accounting.actions.lock_period'))
            ->tooltip(__('accounting.actions.lock_period'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->authorize('lock')
            ->action(function (Period $record): void {
                DomainActionRunner::run(fn (User $actor): Period => app(LockPeriod::class)($actor, $record));

                Notification::make()
                    ->title(__('accounting.notifications.period_locked', ['month' => Display::yearMonth($record->month)]))
                    ->success()
                    ->send();
            });

        return self::tier1(
            $action,
            heading: fn (Period $record): string => __('accounting.actions.lock_period_heading', ['month' => Display::yearMonth($record->month)]),
            description: __('accounting.actions.lock_period_description'),
        );
    }

    public static function unlockPeriod(): Action
    {
        $action = Action::make('unlock')
            ->label(__('accounting.actions.unlock_period'))
            ->tooltip(__('accounting.actions.unlock_period'))
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('danger')
            ->authorize('unlock')
            ->action(function (Period $record): void {
                DomainActionRunner::run(fn (User $actor): Period => app(UnlockPeriod::class)($actor, $record));

                Notification::make()
                    ->title(__('accounting.notifications.period_unlocked', ['month' => Display::yearMonth($record->month)]))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (Period $record): string => __('accounting.actions.unlock_period_heading', ['month' => Display::yearMonth($record->month)]),
            expected: fn (Period $record): string => (string) $record->month,
            submitLabel: fn (Period $record): string => __('accounting.actions.unlock_period_submit', ['month' => Display::yearMonth($record->month)]),
        );
    }

    /**
     * @param  callable(): int  $startYear
     */
    private static function open(string $name, callable $startYear): Action
    {
        $action = Action::make($name)
            ->label(fn (): string => __('accounting.actions.open_fiscal_year_heading', ['code' => FiscalCalendar::code($startYear())]))
            ->icon(Heroicon::OutlinedPlus)
            ->color('primary')
            ->authorize('create', FiscalYear::class)
            ->button()
            ->labeledFrom('md')
            ->modalSubmitActionLabel(fn (): string => __('accounting.actions.open_fiscal_year_submit', ['code' => FiscalCalendar::code($startYear())]))
            ->action(function () use ($startYear): void {
                $year = $startYear();

                DomainActionRunner::run(fn (User $actor): FiscalYear => app(OpenFiscalYear::class)($actor, $year));

                Notification::make()
                    ->title(__('accounting.notifications.fiscal_year_opened', ['code' => FiscalCalendar::code($year)]))
                    ->success()
                    ->send();
            });

        return self::tier2(
            $action,
            heading: fn (): string => __('accounting.actions.open_fiscal_year_heading', ['code' => FiscalCalendar::code($startYear())]),
            rows: fn (): array => ChangeSummary::rows(
                [
                    'code' => __('accounting.fiscal_year.code'),
                    'starts_on' => __('accounting.fiscal_year.starts_on'),
                    'ends_on' => __('accounting.fiscal_year.ends_on'),
                    'periods' => __('accounting.fiscal_year.periods'),
                ],
                [],
                [
                    'code' => FiscalCalendar::code($startYear()),
                    'starts_on' => FiscalCalendar::startsOn($startYear()),
                    'ends_on' => FiscalCalendar::endsOn($startYear()),
                    'periods' => Display::digits(12),
                ],
            ),
            showOld: false,
        );
    }

    private static function nextStartYear(): int
    {
        $latest = FiscalYear::query()->max('start_year');

        return $latest === null ? FiscalCalendar::startYearFor(YearMonth::current()) : (int) $latest + 1;
    }

    private static function previousStartYear(): int
    {
        $earliest = FiscalYear::query()->min('start_year');

        return $earliest === null ? FiscalCalendar::startYearFor(YearMonth::current()) - 1 : (int) $earliest - 1;
    }
}
