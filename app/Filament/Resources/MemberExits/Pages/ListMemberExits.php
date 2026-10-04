<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberExits\Pages;

use App\Filament\Resources\MemberExits\MemberExitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListMemberExits extends ListRecords
{
    protected static string $resource = MemberExitResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('exits.actions.request'))->button()->labeledFrom('md')];
    }
}
