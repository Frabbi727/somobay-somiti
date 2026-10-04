<?php

declare(strict_types=1);

namespace App\Filament\Resources\YearEnds\Actions;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\YearEnd\Actions\ApproveYearEnd;
use App\Domain\YearEnd\Actions\CreditDividendsToSavings;
use App\Domain\YearEnd\Actions\SettleDividend;
use App\Domain\YearEnd\Enums\DividendSettlement;
use App\Domain\YearEnd\Enums\DividendStatus;
use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\DividendLine;
use App\Domain\YearEnd\Models\YearEnd;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

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

    public static function settleDividend(): Action
    {
        $action = Action::make('settle')
            ->label(__('year_end.actions.settle'))
            ->tooltip(__('year_end.actions.settle'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->authorize('settle')
            ->action(function (DividendLine $record, array $data): void {
                $how = $data['how'] instanceof DividendSettlement ? $data['how'] : DividendSettlement::from((string) $data['how']);
                $from = $data['paid_from'] ?? null;

                DomainActionRunner::run(fn (User $actor): DividendLine => app(SettleDividend::class)(
                    $actor,
                    $record,
                    $how,
                    $from instanceof PaymentMethod ? $from : PaymentMethod::tryFrom((string) $from),
                ));

                Notification::make()->title(__('year_end.notifications.settled'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (DividendLine $record): string => __('year_end.actions.settle_heading', [
                'member' => $record->member->displayName(),
                'amount' => Display::money($record->amount_poisha),
            ]),
            expected: fn (DividendLine $record): string => $record->member->member_no,
            submitLabel: fn (DividendLine $record): string => __('year_end.actions.settle_submit', ['amount' => Display::money($record->amount_poisha)]),
            fields: [
                Select::make('how')->label(__('year_end.field.settlement'))->options(DividendSettlement::class)->default(DividendSettlement::Savings->value)->required()->native(false)->live(),
                Select::make('paid_from')
                    ->label(__('expenses.field.paid_from'))
                    ->options(PaymentMethod::class)
                    ->default(PaymentMethod::Cash->value)
                    ->visible(fn (Get $get): bool => in_array($get('how'), [DividendSettlement::Payout, DividendSettlement::Payout->value], true))
                    ->native(false),
            ],
        );
    }

    public static function creditAllToSavings(): Action
    {
        $action = Action::make('creditAll')
            ->label(__('year_end.actions.credit_all'))
            ->tooltip(__('year_end.actions.credit_all'))
            ->icon(Heroicon::OutlinedArrowDownOnSquareStack)
            ->color('primary')
            ->visible(fn (YearEnd $record): bool => $record->status === YearEndStatus::Posted
                && $record->dividendLines()->where('status', DividendStatus::Unpaid)->exists()
                && Gate::allows('settle', $record->dividendLines()->where('status', DividendStatus::Unpaid)->first()))
            ->action(function (YearEnd $record): void {
                ['credited' => $credited, 'failed' => $failed] = DomainActionRunner::run(fn (User $actor): array => app(CreditDividendsToSavings::class)($actor, $record));

                Notification::make()
                    ->title(__('year_end.notifications.credited_all', ['credited' => Display::digits($credited), 'failed' => Display::digits($failed)]))
                    ->color($failed === 0 ? 'success' : 'warning')
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (YearEnd $record): string => __('year_end.actions.credit_all_heading', ['code' => $record->fiscalYear->code]),
            expected: fn (): string => (string) __('confirm.word'),
            submitLabel: __('year_end.actions.credit_all'),
            description: __('year_end.actions.credit_all_description'),
        );
    }
}
