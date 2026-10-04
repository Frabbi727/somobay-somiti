<?php

declare(strict_types=1);

namespace App\Filament\Resources\FundTransfers\Pages;

use App\Domain\Accounting\Models\FundTransfer;
use App\Filament\Resources\FundTransfers\Actions\FundTransferActions;
use App\Filament\Resources\FundTransfers\FundTransferResource;
use App\Filament\Support\Display;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property FundTransfer $record
 */
final class ViewFundTransfer extends ViewRecord
{
    protected static string $resource = FundTransferResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['journalEntry', 'recorder', 'approver']);
    }

    public function getTitle(): string
    {
        return $this->record->transfer_no.' · '.Display::money($this->record->amount_poisha);
    }

    protected function getHeaderActions(): array
    {
        return [
            FundTransferActions::approve(),
            FundTransferActions::reject(),
            FundTransferActions::cancel(),
            FundTransferActions::reverse(),
        ];
    }
}
