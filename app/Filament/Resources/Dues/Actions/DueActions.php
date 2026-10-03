<?php

declare(strict_types=1);

namespace App\Filament\Resources\Dues\Actions;

use App\Domain\Contributions\Actions\ApplyLateFees;
use App\Domain\Contributions\Actions\WaiveLateFee;
use App\Domain\Contributions\Models\Due;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

final class DueActions
{
    use ConfirmsWithTier;

    public static function applyLateFees(): Action
    {
        $action = Action::make('applyLateFees')
            ->label(__('dues.late_fees.apply'))
            ->tooltip(__('dues.late_fees.apply_heading'))
            ->icon(Heroicon::OutlinedClock)
            ->color('warning')
            ->visible(fn (): bool => Gate::allows('generateDues'))
            ->action(function (): void {
                ['count' => $count, 'total' => $total] = DomainActionRunner::run(fn (User $actor): array => app(ApplyLateFees::class)(null, $actor));

                Notification::make()
                    ->title(__('dues.late_fees.applied', ['count' => Display::digits($count), 'amount' => Display::money($total)]))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: __('dues.late_fees.apply_heading'),
            expected: fn (): string => (string) __('confirm.word'),
            submitLabel: __('dues.late_fees.apply'),
            description: __('dues.late_fees.apply_description'),
        );
    }

    public static function waive(): Action
    {
        $action = Action::make('waive')
            ->label(__('dues.late_fees.waive'))
            ->tooltip(__('dues.late_fees.waive'))
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('danger')
            ->authorize('waive')
            ->action(function (Due $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): Due => app(WaiveLateFee::class)($actor, $record, (string) ($data['reason'] ?? '')));

                Notification::make()->title(__('dues.late_fees.waived'))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (Due $record): string => __('dues.late_fees.waive_heading', ['amount' => Display::money($record->amount_poisha)]),
            expected: fn (Due $record): string => $record->member->member_no,
            submitLabel: fn (Due $record): string => __('dues.late_fees.waive_submit', ['amount' => Display::money($record->amount_poisha)]),
            fields: [
                Textarea::make('reason')->label(__('dues.late_fees.reason'))->required()->minLength(5)->rows(2),
            ],
        );
    }
}
