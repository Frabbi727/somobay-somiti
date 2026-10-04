<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLog\Pages;

use App\Filament\Resources\AuditLog\AuditLogResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewAuditEntry extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;
}
