<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\Users\Pages;

use App\Domain\Settings\Actions\CreateStaffUser;
use App\Domain\Settings\Data\StaffUserData;
use App\Filament\Clusters\Settings\Resources\Users\Schemas\UserForm;
use App\Filament\Clusters\Settings\Resources\Users\UserResource;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateUser extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = UserResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return self::tier2(
            parent::getCreateFormAction()->submit(null),
            heading: fn (): string => __('users.actions.create_heading', ['name' => (string) ($this->data['name'] ?? '')]),
            rows: fn (): array => ChangeSummary::rows(UserForm::summaryLabels(), [], UserForm::summaryValues($this->data ?? [])),
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
        return DomainActionRunner::run(fn (User $actor): User => app(CreateStaffUser::class)($actor, StaffUserData::fromForm($data)));
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('users.notifications.created');
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }
}
