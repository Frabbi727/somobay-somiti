<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\Actions;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\CancelInvestment;
use App\Domain\Investments\Actions\CloseInvestment;
use App\Domain\Investments\Actions\ImpairInvestment;
use App\Domain\Investments\Actions\RecordInvestmentIncome;
use App\Domain\Investments\Actions\RejectInvestment;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentIncome;
use App\Domain\Investments\Services\InvestmentLimits;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    public static function income(): Action
    {
        $action = Action::make('income')
            ->label(__('investments.actions.income'))
            ->tooltip(__('investments.actions.income'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->authorize('recordIncome')
            ->action(function (Investment $record, array $data): void {
                $income = DomainActionRunner::run(fn (User $actor): InvestmentIncome => app(RecordInvestmentIncome::class)(
                    $actor,
                    $record,
                    self::money($data['gross'] ?? null),
                    self::money($data['tax_deducted'] ?? null),
                    CarbonImmutable::parse((string) ($data['received_on'] ?? 'today'), YearMonth::TIMEZONE),
                    self::method($data['received_into'] ?? null),
                    is_string($data['reference'] ?? null) && $data['reference'] !== '' ? $data['reference'] : null,
                ));

                Notification::make()
                    ->title(__('investments.notifications.income_recorded', ['voucher' => Display::digits($income->journalEntry->voucher_no)]))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (Investment $record): string => __('investments.actions.income_heading', ['number' => $record->investment_no]),
            expected: fn (Investment $record): string => $record->investment_no,
            submitLabel: __('investments.actions.income_submit'),
            fields: [
                MoneyInput::make('gross')->label(__('investments.field.gross'))->required(),
                MoneyInput::make('tax_deducted')->label(__('investments.field.tax_deducted'))->default('0'),
                DatePicker::make('received_on')->label(__('investments.field.received_on'))->default(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())->native(false)->required(),
                Select::make('received_into')->label(__('investments.field.received_into'))->options(PaymentMethod::class)->default(PaymentMethod::Bank->value)->required()->native(false),
                TextInput::make('reference')->label(__('investments.field.reference'))->maxLength(60),
            ],
        );
    }

    public static function impair(): Action
    {
        $action = Action::make('impair')
            ->label(__('investments.actions.impair'))
            ->tooltip(__('investments.actions.impair'))
            ->icon(Heroicon::OutlinedArrowTrendingDown)
            ->color('danger')
            ->authorize('impair')
            ->action(function (Investment $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): Investment => app(ImpairInvestment::class)(
                    $actor,
                    $record,
                    self::money($data['amount'] ?? null),
                    (string) ($data['reason'] ?? ''),
                    CarbonImmutable::parse((string) ($data['date'] ?? 'today'), YearMonth::TIMEZONE),
                ));
                Notification::make()->title(__('investments.notifications.impaired'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (Investment $record): string => __('investments.actions.impair_heading', ['number' => $record->investment_no, 'book' => Display::money($record->bookValue())]),
            expected: fn (Investment $record): string => $record->investment_no,
            submitLabel: __('investments.actions.impair'),
            description: __('investments.actions.impair_description'),
            fields: [
                MoneyInput::make('amount')->label(__('investments.field.amount'))->required(),
                DatePicker::make('date')->label(__('investments.field.date'))->default(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())->native(false)->required(),
                Textarea::make('reason')->label(__('investments.field.reason'))->required()->minLength(5)->rows(2),
            ],
        );
    }

    public static function close(): Action
    {
        $action = Action::make('close')
            ->label(__('investments.actions.close'))
            ->tooltip(__('investments.actions.close'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->authorize('close')
            ->action(function (Investment $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): Investment => app(CloseInvestment::class)(
                    $actor,
                    $record,
                    self::money($data['capital_returned'] ?? null),
                    CarbonImmutable::parse((string) ($data['date'] ?? 'today'), YearMonth::TIMEZONE),
                    self::method($data['received_into'] ?? null),
                    self::money($data['final_profit'] ?? null),
                    self::money($data['tax_deducted'] ?? null),
                ));
                Notification::make()->title(__('investments.notifications.closed'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (Investment $record): string => __('investments.actions.close_heading', ['number' => $record->investment_no, 'book' => Display::money($record->bookValue())]),
            expected: fn (Investment $record): string => $record->investment_no,
            submitLabel: __('investments.actions.close'),
            description: __('investments.actions.close_description'),
            fields: [
                MoneyInput::make('capital_returned')->label(__('investments.field.capital_returned'))->default(fn (Investment $record): string => $record->bookValue()->toTakaString())->required(),
                MoneyInput::make('final_profit')->label(__('investments.field.final_profit'))->default('0'),
                MoneyInput::make('tax_deducted')->label(__('investments.field.tax_deducted'))->default('0'),
                DatePicker::make('date')->label(__('investments.field.date'))->default(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())->native(false)->required(),
                Select::make('received_into')->label(__('investments.field.received_into'))->options(PaymentMethod::class)->default(PaymentMethod::Bank->value)->required()->native(false),
            ],
        );
    }

    private static function money(mixed $value): Money
    {
        return $value instanceof Money ? $value : Money::zero();
    }

    private static function method(mixed $value): PaymentMethod
    {
        return $value instanceof PaymentMethod ? $value : PaymentMethod::from((string) $value);
    }
}
