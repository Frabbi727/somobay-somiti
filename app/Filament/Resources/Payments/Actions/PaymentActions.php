<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Actions;

use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\CancelPayment;
use App\Domain\Contributions\Actions\RejectPayment;
use App\Domain\Contributions\Actions\ReversePayment;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Reports\ReceiptDocument;
use App\Support\Money\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

final class PaymentActions
{
    use ConfirmsWithTier;

    public static function approve(): Action
    {
        $action = Action::make('approve')
            ->label(__('payments.actions.approve'))
            ->tooltip(__('payments.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('approve')
            ->action(function (Payment $record): void {
                $payment = DomainActionRunner::run(fn (User $actor): Payment => app(ApprovePayment::class)($actor, $record));

                Notification::make()
                    ->title(__('payments.actions.approved', ['voucher' => $payment->journalEntry->voucher_no ?? '']))
                    ->success()
                    ->actions([
                        Action::make('receipt')->label(__('payments.actions.receipt'))->url(ReceiptDocument::signedUrl($payment), shouldOpenInNewTab: true),
                    ])
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (Payment $record): string => __('payments.actions.approve_heading', ['amount' => Display::money($record->amount_poisha), 'member' => $record->member->displayName()]),
            expected: fn (Payment $record): string => $record->member->member_no,
            submitLabel: fn (Payment $record): string => __('payments.actions.approve_submit', ['amount' => Display::money($record->amount_poisha)]),
            description: __('payments.actions.approve_description'),
        );
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('payments.actions.reject'))
            ->tooltip(__('payments.actions.reject'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('reject')
            ->requiresConfirmation()
            ->modalHeading(fn (Payment $record): string => __('payments.actions.reject_heading', ['amount' => Display::money($record->amount_poisha), 'member' => $record->member->displayName()]))
            ->schema([Textarea::make('reason')->label(__('payments.field.reason'))->required()->minLength(5)->rows(2)])
            ->action(function (Payment $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): Payment => app(RejectPayment::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('payments.actions.rejected'))->success()->send();
            });
    }

    public static function cancel(): Action
    {
        $action = Action::make('cancel')
            ->label(__('payments.actions.cancel'))
            ->tooltip(__('payments.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->authorize('cancel')
            ->action(function (Payment $record): void {
                DomainActionRunner::run(fn (User $actor): Payment => app(CancelPayment::class)($actor, $record));
                Notification::make()->title(__('payments.actions.cancelled'))->success()->send();
            });

        return self::tier1($action, fn (Payment $record): string => __('payments.actions.cancel_heading', ['amount' => Display::money($record->amount_poisha)]));
    }

    public static function reverse(): Action
    {
        $action = Action::make('reverse')
            ->label(__('payments.actions.reverse'))
            ->tooltip(__('payments.actions.reverse'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize('reverse')
            ->action(function (Payment $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): Payment => app(ReversePayment::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('payments.actions.reversed'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (Payment $record): string => __('payments.actions.reverse_heading', ['voucher' => $record->journalEntry->voucher_no ?? '']),
            expected: fn (Payment $record): string => $record->journalEntry->voucher_no ?? '',
            submitLabel: fn (Payment $record): string => __('payments.actions.reverse_submit', ['voucher' => $record->journalEntry->voucher_no ?? '']),
            description: __('payments.actions.reverse_description'),
            fields: [Textarea::make('reason')->label(__('payments.field.reason'))->required()->minLength(5)->rows(2)],
        );
    }

    public static function receipt(): Action
    {
        return Action::make('receipt')
            ->label(__('payments.actions.receipt'))
            ->tooltip(__('payments.actions.receipt'))
            ->icon(Heroicon::OutlinedPrinter)
            ->color('info')
            ->visible(fn (Payment $record): bool => in_array($record->status, [PaymentStatus::Approved, PaymentStatus::Reversed], true))
            ->url(fn (Payment $record): string => ReceiptDocument::signedUrl($record), shouldOpenInNewTab: true);
    }

    /**
     * T3 bulk approval: each payment in its own transaction; failures are counted, not fatal.
     */
    public static function bulkApprove(): BulkAction
    {
        return BulkAction::make('bulkApprove')
            ->label(__('payments.actions.bulk_approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (Collection $records): string => __('payments.actions.bulk_approve_heading', [
                'count' => Display::digits($records->count()),
                'amount' => Display::money(Money::sum($records->filter(fn ($record): bool => $record instanceof Payment)->map(fn (Payment $payment): Money => $payment->amount_poisha))),
            ]))
            ->schema([
                TextInput::make('confirm_text')
                    ->label(fn (): string => __('confirm.type_to_confirm', ['text' => __('confirm.word')]))
                    ->required()
                    ->in(fn (): array => [(string) __('confirm.word')])
                    ->validationMessages(['in' => __('confirm.mismatch')]),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $actor = DomainActionRunner::actor();
                $approved = 0;
                $failed = 0;

                foreach ($records as $payment) {
                    if (! $payment instanceof Payment) {
                        continue;
                    }

                    try {
                        app(ApprovePayment::class)($actor, $payment);
                        $approved++;
                    } catch (DomainRuleViolation|AuthorizationException) {
                        $failed++;
                    }
                }

                Notification::make()
                    ->title(__('payments.actions.bulk_result', ['approved' => Display::digits($approved), 'failed' => Display::digits($failed)]))
                    ->color($failed === 0 ? 'success' : 'warning')
                    ->send();
            });
    }
}
