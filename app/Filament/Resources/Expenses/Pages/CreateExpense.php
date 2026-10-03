<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Domain\Accounting\Actions\RecordExpense;
use App\Domain\Accounting\Data\ExpenseData;
use App\Domain\Accounting\Models\Expense;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateExpense extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = ExpenseResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return self::tier2(
            parent::getCreateFormAction()->submit(null)->label(__('expenses.actions.create')),
            heading: __('expenses.actions.create_heading'),
            rows: fn (): array => ChangeSummary::rows(ExpenseForm::summaryLabels(), [], ExpenseForm::summaryValues($this->data ?? [])),
            showOld: false,
        )
            ->mountUsing(fn () => $this->form->validate())
            ->action(fn () => $this->create());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DomainActionRunner::run(fn (User $actor): Expense => app(RecordExpense::class)($actor, ExpenseData::fromForm($data)));
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('expenses.notifications.recorded', ['number' => $this->record instanceof Expense ? $this->record->expense_no : '']);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
