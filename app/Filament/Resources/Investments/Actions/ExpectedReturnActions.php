<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\Actions;

use App\Domain\Investments\Actions\SetExpectedReturn;
use App\Domain\Investments\Data\ExpectedReturnData;
use App\Domain\Investments\Enums\ReturnFrequency;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentReturnExpectation;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * T-12: what an active investment is expected to pay. Tracking only; nothing is posted.
 */
final class ExpectedReturnActions
{
    use ConfirmsWithTier;

    public static function set(): Action
    {
        $action = Action::make('setExpectedReturn')
            ->label(__('investments.returns.actions.set'))
            ->tooltip(__('investments.returns.actions.set'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->authorize('setExpectedReturn')
            ->schema([
                Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                Select::make('frequency')
                    ->label(__('investments.returns.field.frequency'))
                    ->options(ReturnFrequency::class)
                    ->default(ReturnFrequency::Monthly->value)
                    ->required()
                    ->native(false)
                    ->live(),
                MoneyInput::make('amount')
                    ->label(__('investments.returns.field.amount'))
                    ->visible(fn (Get $get): bool => self::frequency($get('frequency')) !== ReturnFrequency::None)
                    ->required(fn (Get $get): bool => self::frequency($get('frequency')) !== ReturnFrequency::None),
                DatePicker::make('starts_on')
                    ->label(__('investments.returns.field.starts_on'))
                    ->helperText(__('investments.returns.field.starts_on_help'))
                    ->default(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())
                    ->native(false)
                    ->required(),
                Textarea::make('notes')->label(__('investments.field.notes'))->rows(2),
            ])
            ->action(function (Investment $record, array $data): void {
                $row = DomainActionRunner::run(fn (User $actor): InvestmentReturnExpectation => app(SetExpectedReturn::class)($actor, $record, ExpectedReturnData::fromForm($data)));

                Notification::make()
                    ->title($row->frequency === ReturnFrequency::None
                        ? __('investments.returns.notifications.stopped', ['date' => Display::date($row->starts_on)])
                        : __('investments.returns.notifications.set', ['amount' => Display::money($row->amount_poisha), 'frequency' => $row->frequency->getLabel(), 'date' => Display::date($row->starts_on)]))
                    ->success()
                    ->send();
            });

        return self::tier1(
            $action,
            heading: fn (Investment $record): string => __('investments.returns.actions.set_heading', ['number' => $record->investment_no, 'name' => $record->displayName()]),
            description: __('investments.returns.actions.set_description'),
        );
    }

    private static function frequency(mixed $value): ?ReturnFrequency
    {
        return $value instanceof ReturnFrequency ? $value : ReturnFrequency::tryFrom(is_string($value) ? $value : '');
    }
}
