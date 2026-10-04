<?php

declare(strict_types=1);

namespace App\Filament\Resources\YearEnds\Pages;

use App\Filament\Resources\YearEnds\YearEndResource;
use Filament\Resources\Pages\ListRecords;

final class ListYearEnds extends ListRecords
{
    protected static string $resource = YearEndResource::class;
}
