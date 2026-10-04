<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberExits\Pages;

use App\Domain\Exits\Models\MemberExit;
use App\Filament\Resources\MemberExits\Actions\MemberExitActions;
use App\Filament\Resources\MemberExits\MemberExitResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property MemberExit $record
 */
final class ViewMemberExit extends ViewRecord
{
    protected static string $resource = MemberExitResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('payouts');
    }

    public function getTitle(): string
    {
        return $this->record->exit_no.' · '.$this->record->member->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [
            MemberExitActions::approve(),
            MemberExitActions::pay(),
            MemberExitActions::cancel(),
        ];
    }
}
