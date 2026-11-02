<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberExits\Actions;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Exits\Actions\ApproveExit;
use App\Domain\Exits\Actions\CancelExit;
use App\Domain\Exits\Actions\PayExit;
use App\Domain\Exits\Actions\ReverseExitPayout;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Exits\Models\MemberExitPayoutReversal;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\MemberExits\Schemas\MemberExitInfolist;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class MemberExitActions
{
    use ConfirmsWithTier;

    public static function approve(): Action
    {
        $action = Action::make('approve')
            ->label(__('exits.actions.approve'))
            ->tooltip(__('exits.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('approve')
            ->action(function (MemberExit $record): void {
                $exit = DomainActionRunner::run(fn (User $actor): MemberExit => app(ApproveExit::class)($actor, $record));
                Notification::make()->title(__('exits.notifications.approved', ['net' => Display::money($exit->net_poisha)]))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (MemberExit $record): string => __('exits.actions.approve_heading', [
                'member' => $record->member->displayName(),
                'net' => Display::money(MemberExitInfolist::preview($record)->net()),
            ]),
            expected: fn (MemberExit $record): string => $record->member->member_no,
            submitLabel: __('exits.actions.approve_submit'),
            description: __('exits.actions.approve_description'),
        );
    }

    public static function pay(): Action
    {
        $action = Action::make('pay')
            ->label(__('exits.actions.pay'))
            ->tooltip(__('exits.actions.pay'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->authorize('pay')
            ->action(function (MemberExit $record, array $data): void {
                $from = $data['paid_from'] ?? null;
                DomainActionRunner::run(fn (User $actor): MemberExit => app(PayExit::class)($actor, $record, $from instanceof PaymentMethod ? $from : PaymentMethod::from((string) $from)));
                Notification::make()->title(__('exits.notifications.paid'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (MemberExit $record): string => __('exits.actions.pay_heading', [
                'net' => Display::money($record->net_poisha),
                'payee' => $record->reason_type->paysNominees() ? __('exits.reason.deceased') : $record->member->displayName(),
            ]),
            expected: fn (MemberExit $record): string => $record->member->member_no,
            submitLabel: fn (MemberExit $record): string => __('exits.actions.pay_submit', ['net' => Display::money($record->net_poisha)]),
            fields: [
                Select::make('paid_from')->label(__('exits.field.paid_from'))->options(PaymentMethod::class)->default(PaymentMethod::Cash->value)->required()->native(false),
            ],
        );
    }

    public static function reversePayout(): Action
    {
        $action = Action::make('reversePayout')
            ->label(__('exits.actions.reverse_payout'))
            ->tooltip(__('exits.actions.reverse_payout'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize('reversePayout')
            ->action(function (MemberExit $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): MemberExit => app(ReverseExitPayout::class)($actor, $record, (string) ($data['reason'] ?? '')));
                $voucher = MemberExitPayoutReversal::query()->where('member_exit_id', $record->id)->latest('id')->first()?->reversalEntry->voucher_no;

                Notification::make()->title(__('exits.notifications.payout_reversed', ['voucher' => Display::digits((string) $voucher)]))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (MemberExit $record): string => __('exits.actions.reverse_payout_heading', ['net' => Display::money($record->net_poisha), 'number' => $record->exit_no]),
            expected: fn (MemberExit $record): string => $record->member->member_no,
            submitLabel: __('exits.actions.reverse_payout'),
            description: __('exits.actions.reverse_payout_description'),
            fields: [Textarea::make('reason')->label(__('exits.field.reason'))->required()->minLength(5)->rows(2)],
        );
    }

    public static function cancel(): Action
    {
        $action = Action::make('cancel')
            ->label(__('exits.actions.cancel'))
            ->tooltip(__('exits.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->schema([Textarea::make('reason')->label(__('exits.field.cancel_reason'))->required()->minLength(5)->rows(2)])
            ->action(function (MemberExit $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): MemberExit => app(CancelExit::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('exits.notifications.cancelled'))->success()->send();
            });

        return self::tier1($action, fn (MemberExit $record): string => __('exits.actions.cancel_heading', ['number' => $record->exit_no]));
    }
}
