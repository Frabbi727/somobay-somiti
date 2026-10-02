<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Domain\Accounting\Actions\CreateAccount as CreateAccountAction;
use App\Domain\Accounting\Data\AccountData;
use App\Domain\Accounting\Models\Account;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Schemas\AccountForm;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateAccount extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = AccountResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return self::tier2(
            parent::getCreateFormAction()->submit(null),
            heading: fn (): string => __('accounting.actions.create_account_heading', ['code' => (string) ($this->data['code'] ?? '')]),
            rows: fn (): array => ChangeSummary::rows(AccountForm::summaryLabels(), [], AccountForm::summaryValues($this->data ?? [])),
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
        return DomainActionRunner::run(
            fn (User $actor): Account => app(CreateAccountAction::class)($actor, AccountData::fromArray($data)),
        );
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('accounting.notifications.account_created', [
            'code' => $this->record instanceof Account ? $this->record->code : '',
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
