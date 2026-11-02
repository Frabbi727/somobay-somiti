<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\Pages;

use App\Domain\Investments\Models\Investment;
use App\Filament\Resources\Investments\Actions\InvestmentActions;
use App\Filament\Resources\Investments\Actions\InvestmentTopUpActions;
use App\Filament\Resources\Investments\InvestmentResource;
use App\Filament\Support\Display;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Investment $record
 */
final class ViewInvestment extends ViewRecord
{
    protected static string $resource = InvestmentResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['ledger.journalEntry', 'income.journalEntry', 'topUps', 'resolution', 'recorder', 'approver']);
    }

    public function getTitle(): string
    {
        return $this->record->investment_no.' · '.Display::money($this->record->ledger->isEmpty() ? $this->record->principal_poisha : $this->record->totalInvested());
    }

    protected function getHeaderActions(): array
    {
        return [
            InvestmentActions::approve(),
            InvestmentActions::reject(),
            InvestmentActions::cancel(),
            InvestmentTopUpActions::add(),
            InvestmentActions::income(),
            InvestmentActions::impair(),
            InvestmentActions::close(),
        ];
    }
}
