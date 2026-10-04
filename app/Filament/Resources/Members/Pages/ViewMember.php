<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Pages;

use App\Domain\Members\Models\Member;
use App\Filament\Resources\Members\Actions\MemberActions;
use App\Filament\Resources\Members\MemberResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Member $record
 */
final class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load('nominees');
    }

    public function getTitle(): string
    {
        return $this->record->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->icon(Heroicon::OutlinedPencilSquare)->color('warning'),
            MemberActions::changeShares(),
            MemberActions::setPortalPassword(),
            MemberActions::deactivate(),
            MemberActions::reactivate(),
        ];
    }
}
