<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalDrafts\Pages;

use App\Domain\Accounting\Actions\SaveJournalDraft;
use App\Domain\Accounting\Data\JournalDraftData;
use App\Domain\Accounting\Models\JournalDraft;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\JournalDrafts\Actions\JournalDraftActions;
use App\Filament\Resources\JournalDrafts\JournalDraftResource;
use App\Filament\Resources\JournalDrafts\Schemas\JournalDraftForm;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property JournalDraft $record
 */
final class EditJournalDraft extends EditRecord
{
    use ConfirmsWithTier;

    protected static string $resource = JournalDraftResource::class;

    public function getTitle(): string
    {
        return __('journal.draft.singular').' #'.Display::digits($this->record->id);
    }

    protected function getHeaderActions(): array
    {
        return [
            JournalDraftActions::post(),
            JournalDraftActions::delete(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return JournalDraftForm::fillFrom($this->record);
    }

    protected function getSaveFormAction(): Action
    {
        return self::tier1(
            parent::getSaveFormAction()->submit(null),
            heading: __('journal.actions.save_draft_heading'),
        )
            ->mountUsing(fn () => $this->form->validate())
            ->action(fn () => $this->save());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DomainActionRunner::run(
            fn (User $actor): JournalDraft => app(SaveJournalDraft::class)($actor, $this->record, JournalDraftData::fromForm($data)),
        );
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('journal.notifications.draft_saved');
    }
}
