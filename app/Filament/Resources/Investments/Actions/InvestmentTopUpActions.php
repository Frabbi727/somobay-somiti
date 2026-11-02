<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\Actions;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Investments\Actions\ApproveInvestmentTopUp;
use App\Domain\Investments\Actions\CancelInvestmentTopUp;
use App\Domain\Investments\Actions\RecordInvestmentTopUp;
use App\Domain\Investments\Actions\RejectInvestmentTopUp;
use App\Domain\Investments\Data\InvestmentTopUpData;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentTopUp;
use App\Domain\Investments\Services\InvestmentLimits;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * More money for an active investment: recorded on the investment page, approved, rejected or
 * cancelled from its "Added investments" list.
 */
final class InvestmentTopUpActions
{
    use ConfirmsWithTier;

    public static function add(): Action
    {
        $action = Action::make('addTopUp')
            ->label(__('investments.top_up.actions.add'))
            ->tooltip(__('investments.top_up.actions.add'))
            ->icon(Heroicon::OutlinedPlusCircle)
            ->color('primary')
            ->authorize('addTopUp')
            ->action(function (Investment $record, array $data): void {
                $topUp = DomainActionRunner::run(fn (User $actor): InvestmentTopUp => app(RecordInvestmentTopUp::class)($actor, $record, InvestmentTopUpData::fromForm($data)));

                Notification::make()
                    ->title(__('investments.top_up.notifications.recorded', ['amount' => Display::money($topUp->amount_poisha), 'number' => $record->investment_no]))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (Investment $record): string => __('investments.top_up.actions.add_heading', ['number' => $record->investment_no, 'name' => $record->displayName()]),
            expected: fn (Investment $record): string => $record->investment_no,
            submitLabel: __('investments.top_up.actions.add_submit'),
            description: fn (Investment $record): string => __('investments.top_up.actions.add_description', ['total' => Display::money($record->totalInvested())]),
            fields: [
                Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                MoneyInput::make('amount')->label(__('investments.field.amount'))->required(),
                DatePicker::make('invested_on')
                    ->label(__('investments.field.date'))
                    ->default(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())
                    ->maxDate(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())
                    ->native(false)
                    ->required(),
                Select::make('funded_from')->label(__('investments.field.funded_from'))->options(PaymentMethod::class)->default(PaymentMethod::Bank->value)->required()->native(false),
                Select::make('resolution_id')
                    ->label(__('investments.field.resolution'))
                    ->options(fn (): array => Resolution::query()
                        ->where('subject', ResolutionSubject::Investment)
                        ->where('status', ResolutionStatus::Passed)
                        ->orderByDesc('id')
                        ->get()
                        ->mapWithKeys(fn (Resolution $resolution): array => [$resolution->id => $resolution->displayName()])
                        ->all())
                    ->required(fn (): bool => ResolutionSubject::Investment->isRequired())
                    ->native(false),
                Textarea::make('notes')->label(__('investments.field.notes'))->rows(2),
            ],
        );
    }

    public static function approve(): Action
    {
        $action = Action::make('approveTopUp')
            ->label(__('investments.top_up.actions.approve'))
            ->tooltip(__('investments.top_up.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('approve')
            ->action(function (InvestmentTopUp $record): void {
                $topUp = DomainActionRunner::run(fn (User $actor): InvestmentTopUp => app(ApproveInvestmentTopUp::class)($actor, $record));

                Notification::make()
                    ->title($topUp->limit_warnings === null
                        ? __('investments.top_up.notifications.approved', ['voucher' => Display::digits($topUp->journalEntry->voucher_no ?? '')])
                        : __('investments.top_up.notifications.approved_with_warnings'))
                    ->color($topUp->limit_warnings === null ? 'success' : 'warning')
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (InvestmentTopUp $record): string => __('investments.top_up.actions.approve_heading', [
                'number' => $record->investment->investment_no,
                'amount' => Display::money($record->amount_poisha),
            ]),
            expected: fn (InvestmentTopUp $record): string => $record->investment->investment_no,
            submitLabel: fn (InvestmentTopUp $record): string => __('investments.top_up.actions.approve_submit', ['amount' => Display::money($record->amount_poisha)]),
            description: fn (InvestmentTopUp $record): string => trim(__('investments.top_up.actions.approve_description', ['source' => $record->funded_from->getLabel()])
                ."\n".implode("\n", app(InvestmentLimits::class)->warningsFor($record->investment->type, $record->amount_poisha))),
        );
    }

    public static function reject(): Action
    {
        $action = Action::make('rejectTopUp')
            ->label(__('investments.top_up.actions.reject'))
            ->tooltip(__('investments.top_up.actions.reject'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('reject')
            ->action(function (InvestmentTopUp $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): InvestmentTopUp => app(RejectInvestmentTopUp::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('investments.top_up.notifications.rejected'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (InvestmentTopUp $record): string => __('investments.top_up.actions.reject_heading', [
                'number' => $record->investment->investment_no,
                'amount' => Display::money($record->amount_poisha),
            ]),
            expected: fn (InvestmentTopUp $record): string => $record->investment->investment_no,
            submitLabel: __('investments.top_up.actions.reject'),
            fields: [Textarea::make('reason')->label(__('investments.field.reason'))->required()->minLength(5)->rows(2)],
        );
    }

    public static function cancel(): Action
    {
        $action = Action::make('cancelTopUp')
            ->label(__('investments.top_up.actions.cancel'))
            ->tooltip(__('investments.top_up.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->action(function (InvestmentTopUp $record): void {
                DomainActionRunner::run(fn (User $actor): InvestmentTopUp => app(CancelInvestmentTopUp::class)($actor, $record));
                Notification::make()->title(__('investments.top_up.notifications.cancelled'))->success()->send();
            });

        return self::tier1($action, fn (InvestmentTopUp $record): string => __('investments.top_up.actions.cancel_heading', [
            'number' => $record->investment->investment_no,
            'amount' => Display::money($record->amount_poisha),
        ]));
    }
}
