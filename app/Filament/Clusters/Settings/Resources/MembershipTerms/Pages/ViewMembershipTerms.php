<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\MembershipTerms\Pages;

use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Actions\MembershipTermsActions;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\MembershipTermsResource;
use App\Filament\Support\Display;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property MembershipTerms $record
 */
final class ViewMembershipTerms extends ViewRecord
{
    protected static string $resource = MembershipTermsResource::class;

    public function getTitle(): string
    {
        return $this->record->version === null
            ? __('terms.admin.draft_title')
            : __('terms.admin.version_title', ['version' => Display::digits($this->record->version)]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->icon(Heroicon::OutlinedPencilSquare)->color('warning'),
            MembershipTermsActions::publish(),
        ];
    }
}
