<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Actions;

use App\Domain\Accounting\Actions\ApproveExpense;
use App\Domain\Accounting\Actions\CancelExpense;
use App\Domain\Accounting\Actions\RejectExpense;
use App\Domain\Accounting\Actions\ReverseExpense;
use App\Domain\Accounting\Models\Expense;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Expense record actions shared by the table and the view page.
 */
final class ExpenseActions
{
    use ConfirmsWithTier;

    public static function approve(): Action
    {
        $action = Action::make('approve')
            ->label(__('expenses.actions.approve'))
            ->tooltip(__('expenses.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('approve')
            ->action(function (Expense $record): void {
                $expense = DomainActionRunner::run(fn (User $actor): Expense => app(ApproveExpense::class)($actor, $record));

                Notification::make()
                    ->title(__('expenses.notifications.approved', ['voucher' => Display::digits($expense->journalEntry->voucher_no ?? '')]))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (Expense $record): string => __('expenses.actions.approve_heading', [
                'number' => $record->expense_no,
                'amount' => Display::money($record->amount_poisha),
                'source' => $record->paid_from->getLabel(),
            ]),
            expected: fn (Expense $record): string => $record->expense_no,
            submitLabel: fn (Expense $record): string => __('expenses.actions.approve_submit', ['amount' => Display::money($record->amount_poisha)]),
            description: __('expenses.actions.approve_description'),
        );
    }

    public static function reject(): Action
    {
        $action = Action::make('reject')
            ->label(__('expenses.actions.reject'))
            ->tooltip(__('expenses.actions.reject'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('reject')
            ->action(function (Expense $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): Expense => app(RejectExpense::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('expenses.notifications.rejected'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (Expense $record): string => __('expenses.actions.reject_heading', ['number' => $record->expense_no]),
            expected: fn (Expense $record): string => $record->expense_no,
            submitLabel: __('expenses.actions.reject'),
            fields: [Textarea::make('reason')->label(__('expenses.field.reason'))->required()->minLength(5)->rows(2)],
        );
    }

    public static function cancel(): Action
    {
        $action = Action::make('cancel')
            ->label(__('expenses.actions.cancel'))
            ->tooltip(__('expenses.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->action(function (Expense $record): void {
                DomainActionRunner::run(fn (User $actor): Expense => app(CancelExpense::class)($actor, $record));
                Notification::make()->title(__('expenses.notifications.cancelled'))->success()->send();
            });

        return self::tier1($action, fn (Expense $record): string => __('expenses.actions.cancel_heading', ['number' => $record->expense_no]));
    }

    public static function reverse(): Action
    {
        $action = Action::make('reverse')
            ->label(__('expenses.actions.reverse'))
            ->tooltip(__('expenses.actions.reverse'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize('reverse')
            ->action(function (Expense $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): Expense => app(ReverseExpense::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('expenses.notifications.reversed'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (Expense $record): string => __('expenses.actions.reverse_heading', ['number' => $record->expense_no, 'amount' => Display::money($record->amount_poisha)]),
            expected: fn (Expense $record): string => $record->expense_no,
            submitLabel: __('expenses.actions.reverse'),
            description: __('expenses.actions.reverse_description'),
            fields: [Textarea::make('reason')->label(__('expenses.field.reason'))->required()->minLength(5)->rows(2)],
        );
    }
}
