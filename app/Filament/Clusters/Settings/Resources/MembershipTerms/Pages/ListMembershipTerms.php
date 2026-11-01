<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\MembershipTerms\Pages;

use App\Filament\Clusters\Settings\Resources\MembershipTerms\Actions\MembershipTermsActions;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\MembershipTermsResource;
use Filament\Resources\Pages\ListRecords;

final class ListMembershipTerms extends ListRecords
{
    protected static string $resource = MembershipTermsResource::class;

    protected function getHeaderActions(): array
    {
        return [MembershipTermsActions::newDraft()];
    }
}
