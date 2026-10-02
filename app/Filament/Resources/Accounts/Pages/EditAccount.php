<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Domain\Accounting\Actions\UpdateAccount;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Accounting\Models\Account;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Actions\AccountActions;
use App\Filament\Resources\Accounts\Schemas\AccountForm;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Account $record
 */
final class EditAccount extends EditRecord
{
    use ConfirmsWithTier;

    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            AccountActions::toggleActive(),
            AccountActions::delete(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return self::tier2(
            parent::getSaveFormAction()->submit(null),
            heading: fn (): string => __('accounting.actions.save_account_heading', ['code' => $this->record->code]),
            rows: fn (): array => ChangeSummary::rows(
                AccountForm::summaryLabels(),
                AccountForm::summaryValues($this->record),
                AccountForm::summaryValues($this->data ?? []),
            ),
        )
            ->mountUsing(fn () => $this->form->validate())
            ->action(fn () => $this->save());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DomainActionRunner::run(
            fn (User $actor): Account => app(UpdateAccount::class)($actor, $this->record, AccountData::fromArray($data)),
        );
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('accounting.notifications.account_saved', ['code' => $this->record->code]);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
