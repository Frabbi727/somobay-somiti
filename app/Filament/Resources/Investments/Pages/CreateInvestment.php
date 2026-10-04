<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\Pages;

use App\Domain\Investments\Actions\RecordInvestment;
use App\Domain\Investments\Data\InvestmentData;
use App\Domain\Investments\Models\Investment;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\Investments\InvestmentResource;
use App\Filament\Resources\Investments\Schemas\InvestmentForm;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateInvestment extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = InvestmentResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return self::tier2(
            parent::getCreateFormAction()->submit(null)->label(__('investments.actions.create')),
            heading: __('investments.actions.create_heading'),
            rows: fn (): array => ChangeSummary::rows(InvestmentForm::summaryLabels(), [], InvestmentForm::summaryValues($this->data ?? [])),
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
        return DomainActionRunner::run(fn (User $actor): Investment => app(RecordInvestment::class)($actor, InvestmentData::fromForm($data)));
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('investments.notifications.recorded', ['number' => $this->record instanceof Investment ? $this->record->investment_no : '']);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
