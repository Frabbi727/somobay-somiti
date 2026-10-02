<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalDrafts\Actions;

use App\Domain\Accounting\Actions\DeleteJournalDraft;
use App\Domain\Accounting\Actions\PostJournalDraft;
use App\Domain\Accounting\Models\JournalDraft;
use App\Domain\Accounting\Models\JournalEntry;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\JournalDrafts\JournalDraftResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class JournalDraftActions
{
    use ConfirmsWithTier;

    public static function post(): Action
    {
        $action = Action::make('post')
            ->label(__('journal.actions.post'))
            ->tooltip(__('journal.actions.post'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('post')
            ->action(function (JournalDraft $record, Action $action): void {
                $entry = DomainActionRunner::run(fn (User $actor): JournalEntry => app(PostJournalDraft::class)($actor, $record));

                Notification::make()
                    ->title(__('journal.notifications.posted', ['voucher' => $entry->voucher_no]))
                    ->success()
                    ->send();

                $action->redirect(JournalEntryResource::getUrl('view', ['record' => $entry]));
            });

        return self::tier3(
            $action,
            heading: fn (JournalDraft $record): string => __('journal.actions.post_heading', ['id' => Display::digits($record->id), 'amount' => Display::money($record->totalDebit())]),
            expected: fn (): string => (string) __('confirm.word'),
            submitLabel: fn (JournalDraft $record): string => __('journal.actions.post_submit', ['amount' => Display::money($record->totalDebit())]),
            description: __('journal.actions.post_description'),
        );
    }

    public static function delete(): Action
    {
        $action = Action::make('delete')
            ->label(__('filament-actions::delete.single.label'))
            ->tooltip(__('filament-actions::delete.single.label'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize('delete')
            ->action(function (JournalDraft $record, Action $action): void {
                DomainActionRunner::run(fn (User $actor) => app(DeleteJournalDraft::class)($actor, $record));

                Notification::make()
                    ->title(__('journal.notifications.draft_deleted', ['id' => Display::digits($record->id)]))
                    ->success()
                    ->send();

                $action->redirect(JournalDraftResource::getUrl('index'));
            });

        return self::tier3(
            $action,
            heading: fn (JournalDraft $record): string => __('journal.actions.delete_draft_heading', ['id' => Display::digits($record->id)]),
            expected: fn (JournalDraft $record): string => (string) $record->id,
            submitLabel: fn (JournalDraft $record): string => __('journal.actions.delete_draft_submit', ['id' => Display::digits($record->id)]),
        );
    }
}
