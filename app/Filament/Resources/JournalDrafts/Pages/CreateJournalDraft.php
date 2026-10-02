<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalDrafts\Pages;

use App\Domain\Accounting\Actions\SaveJournalDraft;
use App\Domain\Accounting\Data\JournalDraftData;
use App\Domain\Accounting\Models\JournalDraft;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\JournalDrafts\JournalDraftResource;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateJournalDraft extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = JournalDraftResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return self::tier1(
            parent::getCreateFormAction()->submit(null),
            heading: __('journal.actions.save_draft_heading'),
        )
            ->mountUsing(fn () => $this->form->validate())
            ->action(fn () => $this->create());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DomainActionRunner::run(
            fn (User $actor): JournalDraft => app(SaveJournalDraft::class)($actor, null, JournalDraftData::fromForm($data)),
        );
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('journal.notifications.draft_saved');
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
