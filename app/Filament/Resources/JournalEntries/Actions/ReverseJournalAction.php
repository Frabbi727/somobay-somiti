<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Models\JournalEntry;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class ReverseJournalAction
{
    use ConfirmsWithTier;

    public static function make(): Action
    {
        $action = Action::make('reverse')
            ->label(__('journal.actions.reverse'))
            ->tooltip(__('journal.actions.reverse'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize('reverse')
            ->action(function (JournalEntry $record, array $data, Action $action): void {
                $reversal = DomainActionRunner::run(
                    fn (User $actor): JournalEntry => app(ReverseJournal::class)($actor, $record, (string) ($data['reason'] ?? '')),
                );

                Notification::make()
                    ->title(__('journal.notifications.reversed', ['voucher' => $record->voucher_no, 'reversal' => $reversal->voucher_no]))
                    ->success()
                    ->send();

                $action->redirect(JournalEntryResource::getUrl('view', ['record' => $reversal]));
            });

        return self::tier3(
            $action,
            heading: fn (JournalEntry $record): string => __('journal.actions.reverse_heading', ['voucher' => $record->voucher_no]),
            expected: fn (JournalEntry $record): string => $record->voucher_no,
            submitLabel: fn (JournalEntry $record): string => __('journal.actions.reverse_submit', ['voucher' => $record->voucher_no]),
            description: __('journal.actions.reverse_description'),
            fields: [
                Textarea::make('reason')
                    ->label(__('journal.actions.reason'))
                    ->required()
                    ->minLength(ReverseJournal::MIN_REASON_LENGTH)
                    ->maxLength(1000)
                    ->rows(2),
            ],
        );
    }
}
