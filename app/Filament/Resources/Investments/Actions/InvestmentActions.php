<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\Actions;

use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\CancelInvestment;
use App\Domain\Investments\Actions\RejectInvestment;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Services\InvestmentLimits;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class InvestmentActions
{
    use ConfirmsWithTier;

    public static function approve(): Action
    {
        $action = Action::make('approve')
            ->label(__('investments.actions.approve'))
            ->tooltip(__('investments.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('approve')
            ->action(function (Investment $record): void {
                $investment = DomainActionRunner::run(fn (User $actor): Investment => app(ApproveInvestment::class)($actor, $record));

                Notification::make()
                    ->title($investment->limit_warnings === null
                        ? __('investments.notifications.approved', ['voucher' => Display::digits($investment->journalEntry->voucher_no ?? '')])
                        : __('investments.notifications.approved_with_warnings'))
                    ->color($investment->limit_warnings === null ? 'success' : 'warning')
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (Investment $record): string => __('investments.actions.approve_heading', [
                'number' => $record->investment_no,
                'amount' => Display::money($record->principal_poisha),
                'institution' => $record->institution,
            ]),
            expected: fn (Investment $record): string => $record->investment_no,
            submitLabel: fn (Investment $record): string => __('investments.actions.approve_submit', ['amount' => Display::money($record->principal_poisha)]),
            description: fn (Investment $record): string => trim(__('investments.actions.approve_description', ['source' => $record->funded_from->getLabel()])
                ."\n".implode("\n", app(InvestmentLimits::class)->warningsFor($record->type, $record->principal_poisha))),
        );
    }

    public static function reject(): Action
    {
        $action = Action::make('reject')
            ->label(__('investments.actions.reject'))
            ->tooltip(__('investments.actions.reject'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('reject')
            ->action(function (Investment $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): Investment => app(RejectInvestment::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('investments.notifications.rejected'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (Investment $record): string => __('investments.actions.reject_heading', ['number' => $record->investment_no]),
            expected: fn (Investment $record): string => $record->investment_no,
            submitLabel: __('investments.actions.reject'),
            fields: [Textarea::make('reason')->label(__('investments.field.reason'))->required()->minLength(5)->rows(2)],
        );
    }

    public static function cancel(): Action
    {
        $action = Action::make('cancel')
            ->label(__('investments.actions.cancel'))
            ->tooltip(__('investments.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->action(function (Investment $record): void {
                DomainActionRunner::run(fn (User $actor): Investment => app(CancelInvestment::class)($actor, $record));
                Notification::make()->title(__('investments.notifications.cancelled'))->success()->send();
            });

        return self::tier1($action, fn (Investment $record): string => __('investments.actions.cancel_heading', ['number' => $record->investment_no]));
    }
}
