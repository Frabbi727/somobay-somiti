<?php

declare(strict_types=1);

namespace App\Filament\Resources\StatementImports\Pages;

use App\Filament\Resources\StatementImports\StatementImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListStatementImports extends ListRecords
{
    protected static string $resource = StatementImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('statements.actions.import'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->button()
                ->labeledFrom('md'),
        ];
    }
}
