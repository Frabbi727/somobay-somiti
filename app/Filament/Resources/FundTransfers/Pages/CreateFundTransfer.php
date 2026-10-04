<?php

declare(strict_types=1);

namespace App\Filament\Resources\FundTransfers\Pages;

use App\Domain\Accounting\Actions\RecordFundTransfer;
use App\Domain\Accounting\Data\FundTransferData;
use App\Domain\Accounting\Models\FundTransfer;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\FundTransfers\FundTransferResource;
use App\Filament\Resources\FundTransfers\Schemas\FundTransferForm;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateFundTransfer extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = FundTransferResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return self::tier2(
            parent::getCreateFormAction()->submit(null)->label(__('transfers.actions.create')),
            heading: __('transfers.actions.create_heading'),
            rows: fn (): array => ChangeSummary::rows(FundTransferForm::summaryLabels(), [], FundTransferForm::summaryValues($this->data ?? [])),
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
        return DomainActionRunner::run(fn (User $actor): FundTransfer => app(RecordFundTransfer::class)($actor, FundTransferData::fromForm($data)));
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('transfers.notifications.recorded', ['number' => $this->record instanceof FundTransfer ? $this->record->transfer_no : '']);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
