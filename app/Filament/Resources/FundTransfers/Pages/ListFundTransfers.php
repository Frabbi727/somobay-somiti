<?php

declare(strict_types=1);

namespace App\Filament\Resources\FundTransfers\Pages;

use App\Filament\Resources\FundTransfers\FundTransferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListFundTransfers extends ListRecords
{
    protected static string $resource = FundTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('transfers.actions.create'))
                ->button()
                ->labeledFrom('md'),
        ];
    }
}
