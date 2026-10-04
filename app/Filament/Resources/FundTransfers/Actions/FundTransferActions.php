<?php

declare(strict_types=1);

namespace App\Filament\Resources\FundTransfers\Actions;

use App\Domain\Accounting\Actions\ApproveFundTransfer;
use App\Domain\Accounting\Actions\CancelFundTransfer;
use App\Domain\Accounting\Actions\RejectFundTransfer;
use App\Domain\Accounting\Actions\ReverseFundTransfer;
use App\Domain\Accounting\Models\FundTransfer;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Fund transfer record actions shared by the table and the view page.
 */
final class FundTransferActions
{
    use ConfirmsWithTier;

    public static function approve(): Action
    {
        $action = Action::make('approve')
            ->label(__('transfers.actions.approve'))
            ->tooltip(__('transfers.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('approve')
            ->action(function (FundTransfer $record): void {
                $transfer = DomainActionRunner::run(fn (User $actor): FundTransfer => app(ApproveFundTransfer::class)($actor, $record));

                Notification::make()
                    ->title(__('transfers.notifications.approved', ['voucher' => Display::digits($transfer->journalEntry->voucher_no ?? '')]))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (FundTransfer $record): string => __('transfers.actions.approve_heading', [
                'number' => $record->transfer_no,
                'amount' => Display::money($record->amount_poisha),
                'from' => $record->from_method->getLabel(),
                'to' => $record->to_method->getLabel(),
            ]),
            expected: fn (FundTransfer $record): string => $record->transfer_no,
            submitLabel: fn (FundTransfer $record): string => __('transfers.actions.approve_submit', ['amount' => Display::money($record->amount_poisha)]),
            description: __('transfers.actions.approve_description'),
        );
    }

    public static function reject(): Action
    {
        $action = Action::make('reject')
            ->label(__('transfers.actions.reject'))
            ->tooltip(__('transfers.actions.reject'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('reject')
            ->action(function (FundTransfer $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): FundTransfer => app(RejectFundTransfer::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('transfers.notifications.rejected'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (FundTransfer $record): string => __('transfers.actions.reject_heading', ['number' => $record->transfer_no]),
            expected: fn (FundTransfer $record): string => $record->transfer_no,
            submitLabel: __('transfers.actions.reject'),
            fields: [Textarea::make('reason')->label(__('transfers.field.reason'))->required()->minLength(5)->rows(2)],
        );
    }

    public static function cancel(): Action
    {
        $action = Action::make('cancel')
            ->label(__('transfers.actions.cancel'))
            ->tooltip(__('transfers.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->action(function (FundTransfer $record): void {
                DomainActionRunner::run(fn (User $actor): FundTransfer => app(CancelFundTransfer::class)($actor, $record));
                Notification::make()->title(__('transfers.notifications.cancelled'))->success()->send();
            });

        return self::tier1($action, fn (FundTransfer $record): string => __('transfers.actions.cancel_heading', ['number' => $record->transfer_no]));
    }

    public static function reverse(): Action
    {
        $action = Action::make('reverse')
            ->label(__('transfers.actions.reverse'))
            ->tooltip(__('transfers.actions.reverse'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize('reverse')
            ->action(function (FundTransfer $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): FundTransfer => app(ReverseFundTransfer::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('transfers.notifications.reversed'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (FundTransfer $record): string => __('transfers.actions.reverse_heading', ['number' => $record->transfer_no, 'amount' => Display::money($record->amount_poisha)]),
            expected: fn (FundTransfer $record): string => $record->transfer_no,
            submitLabel: __('transfers.actions.reverse'),
            description: __('transfers.actions.reverse_description'),
            fields: [Textarea::make('reason')->label(__('transfers.field.reason'))->required()->minLength(5)->rows(2)],
        );
    }
}
