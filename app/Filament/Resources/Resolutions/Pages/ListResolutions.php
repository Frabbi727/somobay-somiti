<?php

declare(strict_types=1);

namespace App\Filament\Resources\Resolutions\Pages;

use App\Filament\Resources\Resolutions\ResolutionResource;
use Filament\Resources\Pages\ListRecords;

final class ListResolutions extends ListRecords
{
    protected static string $resource = ResolutionResource::class;
}
