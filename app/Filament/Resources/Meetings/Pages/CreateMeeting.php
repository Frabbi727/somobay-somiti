<?php

declare(strict_types=1);

namespace App\Filament\Resources\Meetings\Pages;

use App\Domain\Governance\Actions\CreateMeeting as CreateMeetingAction;
use App\Domain\Governance\Data\MeetingData;
use App\Domain\Governance\Models\Meeting;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\Meetings\MeetingResource;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateMeeting extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = MeetingResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return self::tier1(parent::getCreateFormAction()->submit(null), __('governance.actions.create_heading'))
            ->mountUsing(fn () => $this->form->validate())
            ->action(fn () => $this->create());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DomainActionRunner::run(fn (User $actor): Meeting => app(CreateMeetingAction::class)($actor, MeetingData::fromForm($data)));
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('governance.notifications.created', ['number' => $this->record instanceof Meeting ? $this->record->meeting_no : '']);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
