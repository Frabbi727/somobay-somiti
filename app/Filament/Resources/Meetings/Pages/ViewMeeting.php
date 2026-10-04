<?php

declare(strict_types=1);

namespace App\Filament\Resources\Meetings\Pages;

use App\Domain\Governance\Models\Meeting;
use App\Filament\Resources\Meetings\Actions\MeetingActions;
use App\Filament\Resources\Meetings\MeetingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property Meeting $record
 */
final class ViewMeeting extends ViewRecord
{
    protected static string $resource = MeetingResource::class;

    public function getTitle(): string
    {
        return $this->record->meeting_no.' · '.$this->record->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            MeetingActions::attendance(),
            MeetingActions::hold(),
            EditAction::make(),
            MeetingActions::cancel(),
        ];
    }
}
