<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberApplications\Pages;

use App\Filament\Resources\MemberApplications\Actions\RegistrationActions;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use Filament\Resources\Pages\ListRecords;

final class ListMemberApplications extends ListRecords
{
    protected static string $resource = MemberApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [RegistrationActions::invite()];
    }
}
