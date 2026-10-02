<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Actions;

use App\Domain\Accounting\Actions\DeleteAccount;
use App\Domain\Accounting\Actions\RestoreAccount;
use App\Domain\Accounting\Actions\SetAccountActive;
use App\Domain\Accounting\Models\Account;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Account record actions shared by the table and the view/edit pages.
 */
final class AccountActions
{
    use ConfirmsWithTier;

    public static function toggleActive(): Action
    {
        $action = Action::make('toggleActive')
            ->label(fn (Account $record): string => __($record->is_active ? 'accounting.actions.deactivate' : 'accounting.actions.activate'))
            ->tooltip(fn (Account $record): string => __($record->is_active ? 'accounting.actions.deactivate' : 'accounting.actions.activate'))
            ->icon(fn (Account $record): Heroicon => $record->is_active ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
            ->color(fn (Account $record): string => $record->is_active ? 'danger' : 'success')
            ->authorize('update')
            ->hidden(fn (Account $record): bool => $record->trashed())
            ->action(function (Account $record): void {
                $active = ! $record->is_active;

                DomainActionRunner::run(fn (User $actor): Account => app(SetAccountActive::class)($actor, $record, $active));

                Notification::make()
                    ->title(__($active ? 'accounting.notifications.account_activated' : 'accounting.notifications.account_deactivated', ['code' => $record->code]))
                    ->success()
                    ->send();
            });

        return self::tier2(
            $action,
            heading: fn (Account $record): string => __($record->is_active ? 'accounting.actions.deactivate_heading' : 'accounting.actions.activate_heading', ['account' => $record->displayName()]),
            rows: fn (Account $record): array => ChangeSummary::rows(
                ['is_active' => __('accounting.account.is_active')],
                ['is_active' => $record->is_active],
                ['is_active' => ! $record->is_active],
            ),
            description: fn (Account $record): string => $record->is_active ? (string) __('accounting.actions.deactivate_description') : '',
        );
    }

    public static function delete(): Action
    {
        $action = Action::make('delete')
            ->label(__('filament-actions::delete.single.label'))
            ->tooltip(__('filament-actions::delete.single.label'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize('delete')
            ->hidden(fn (Account $record): bool => $record->trashed())
            ->successRedirectUrl(fn (): string => AccountResource::getUrl('index'))
            ->action(function (Account $record, Action $action): void {
                DomainActionRunner::run(fn (User $actor) => app(DeleteAccount::class)($actor, $record));

                Notification::make()
                    ->title(__('accounting.notifications.account_deleted', ['code' => $record->code]))
                    ->success()
                    ->send();

                $action->success();
            });

        return self::tier3(
            $action,
            heading: fn (Account $record): string => __('accounting.actions.delete_heading', ['account' => $record->displayName()]),
            expected: fn (Account $record): string => $record->code,
            submitLabel: fn (Account $record): string => __('accounting.actions.delete_submit', ['code' => $record->code]),
        );
    }

    public static function restore(): Action
    {
        $action = Action::make('restore')
            ->label(__('filament-actions::restore.single.label'))
            ->tooltip(__('filament-actions::restore.single.label'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->authorize('restore')
            ->visible(fn (Account $record): bool => $record->trashed())
            ->action(function (Account $record): void {
                DomainActionRunner::run(fn (User $actor): Account => app(RestoreAccount::class)($actor, $record));

                Notification::make()
                    ->title(__('filament-actions::restore.single.notifications.restored.title'))
                    ->success()
                    ->send();
            });

        return self::tier1(
            $action,
            heading: fn (Account $record): string => __('filament-actions::restore.single.modal.heading', ['label' => $record->displayName()]),
        );
    }
}
