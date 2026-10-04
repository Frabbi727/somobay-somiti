<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLog\Pages;

use App\Filament\Resources\AuditLog\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

final class ListAuditEntries extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    public function getSubheading(): string
    {
        return __('audit.subheading');
    }
}
