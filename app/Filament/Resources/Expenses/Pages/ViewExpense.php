<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Domain\Accounting\Models\Expense;
use App\Filament\Resources\Expenses\Actions\ExpenseActions;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\Display;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Expense $record
 */
final class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['account', 'journalEntry', 'recorder', 'approver']);
    }

    public function getTitle(): string
    {
        return $this->record->expense_no.' · '.Display::money($this->record->amount_poisha);
    }

    protected function getHeaderActions(): array
    {
        return [
            ExpenseActions::approve(),
            ExpenseActions::reject(),
            ExpenseActions::cancel(),
            ExpenseActions::reverse(),
        ];
    }
}
