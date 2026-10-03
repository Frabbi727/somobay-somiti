<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\Users\Actions;

use App\Domain\Settings\Actions\SetStaffUserActive;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class UserActions
{
    use ConfirmsWithTier;

    public static function deactivate(): Action
    {
        $action = Action::make('deactivate')
            ->label(__('users.actions.deactivate'))
            ->tooltip(__('users.actions.deactivate'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('update')
            ->visible(fn (User $record): bool => $record->isActive())
            ->action(function (User $record): void {
                DomainActionRunner::run(fn (User $actor): User => app(SetStaffUserActive::class)($actor, $record, false));
                Notification::make()->title(__('users.notifications.deactivated', ['name' => $record->name]))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (User $record): string => __('users.actions.deactivate_heading', ['name' => $record->name]),
            expected: fn (User $record): string => (string) $record->email,
            submitLabel: __('users.actions.deactivate'),
            description: __('users.actions.deactivate_description'),
        );
    }

    public static function reactivate(): Action
    {
        $action = Action::make('reactivate')
            ->label(__('users.actions.reactivate'))
            ->tooltip(__('users.actions.reactivate'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('success')
            ->authorize('update')
            ->visible(fn (User $record): bool => ! $record->isActive())
            ->action(function (User $record): void {
                DomainActionRunner::run(fn (User $actor): User => app(SetStaffUserActive::class)($actor, $record, true));
                Notification::make()->title(__('users.notifications.reactivated', ['name' => $record->name]))->success()->send();
            });

        return self::tier1($action, fn (User $record): string => __('users.actions.reactivate_heading', ['name' => $record->name]));
    }
}
