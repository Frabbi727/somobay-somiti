<?php

declare(strict_types=1);

namespace App\Filament\Resources\Meetings\Pages;

use App\Domain\Governance\Actions\UpdateMeeting;
use App\Domain\Governance\Data\MeetingData;
use App\Domain\Governance\Models\Meeting;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\Meetings\MeetingResource;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Meeting $record
 */
final class EditMeeting extends EditRecord
{
    use ConfirmsWithTier;

    protected static string $resource = MeetingResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function getSaveFormAction(): Action
    {
        return self::tier1(
            parent::getSaveFormAction()->submit(null),
            fn (): string => __('governance.actions.save_heading', ['number' => $this->record->meeting_no]),
        )
            ->mountUsing(fn () => $this->form->validate())
            ->action(fn () => $this->save());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DomainActionRunner::run(fn (User $actor): Meeting => app(UpdateMeeting::class)($actor, $this->record, MeetingData::fromForm($data)));
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('governance.notifications.saved');
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
