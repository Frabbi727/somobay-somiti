<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberApplications\Pages;

use App\Domain\Members\Registration\Models\MemberApplication;
use App\Filament\Resources\MemberApplications\Actions\RegistrationActions;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property MemberApplication $record
 */
final class ViewMemberApplication extends ViewRecord
{
    protected static string $resource = MemberApplicationResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['nominees.nomineeRelation', 'decisions']);
    }

    public function getTitle(): string
    {
        return $this->record->name_bn ?? $this->record->mobile;
    }

    protected function getHeaderActions(): array
    {
        return [
            RegistrationActions::approve(),
            RegistrationActions::sendBack(),
            RegistrationActions::reject(),
            RegistrationActions::openMember(),
        ];
    }
}
