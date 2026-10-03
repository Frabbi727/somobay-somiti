<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\SmsMessages\Pages;

use App\Filament\Clusters\Settings\Resources\SmsMessages\SmsMessageResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmsMessages extends ListRecords
{
    protected static string $resource = SmsMessageResource::class;
}
