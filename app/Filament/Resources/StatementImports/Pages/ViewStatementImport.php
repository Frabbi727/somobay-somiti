<?php

declare(strict_types=1);

namespace App\Filament\Resources\StatementImports\Pages;

use App\Domain\Accounting\Models\StatementImport;
use App\Filament\Resources\StatementImports\StatementImportResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property StatementImport $record
 */
final class ViewStatementImport extends ViewRecord
{
    protected static string $resource = StatementImportResource::class;

    public function getTitle(): string
    {
        return $this->record->filename;
    }
}
