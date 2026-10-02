<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Domain\Accounting\Models\Account;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Actions\AccountActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Account $record
 */
final class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    public function getTitle(): string
    {
        return $this->record->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('warning')
                ->hidden(fn (): bool => $this->record->trashed()),
            AccountActions::toggleActive(),
            AccountActions::delete(),
            AccountActions::restore(),
        ];
    }
}
