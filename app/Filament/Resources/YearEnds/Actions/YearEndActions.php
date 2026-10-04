<?php

declare(strict_types=1);

namespace App\Filament\Resources\YearEnds\Actions;

use App\Domain\YearEnd\Actions\ApproveYearEnd;
use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\YearEnd;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class YearEndActions
{
    use ConfirmsWithTier;

    public static function approve(): Action
    {
        $action = Action::make('approve')
            ->label(__('year_end.actions.approve'))
            ->tooltip(__('year_end.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('approve')
            ->action(function (YearEnd $record): void {
                $yearEnd = DomainActionRunner::run(fn (User $actor): YearEnd => app(ApproveYearEnd::class)($actor, $record));

                Notification::make()
                    ->title(__($yearEnd->status === YearEndStatus::Posted ? 'year_end.notifications.posted' : 'year_end.notifications.approved'))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (YearEnd $record): string => __('year_end.actions.approve_heading', [
                'code' => $record->fiscalYear->code,
                'profit' => Display::money($record->net_profit_poisha),
                'pool' => Display::money($record->dividend_pool_poisha),
            ]),
            expected: fn (YearEnd $record): string => $record->fiscalYear->code,
            submitLabel: __('year_end.actions.approve_submit'),
            description: __('year_end.actions.approve_description'),
        );
    }
}
