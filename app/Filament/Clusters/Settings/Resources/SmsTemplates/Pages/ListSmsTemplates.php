<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\SmsTemplates\Pages;

use App\Filament\Clusters\Settings\Resources\SmsTemplates\SmsTemplateResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmsTemplates extends ListRecords
{
    protected static string $resource = SmsTemplateResource::class;
}
