<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\Users\Pages;

use App\Domain\Settings\Actions\UpdateStaffUser;
use App\Domain\Settings\Data\StaffUserData;
use App\Filament\Clusters\Settings\Resources\Users\Actions\UserActions;
use App\Filament\Clusters\Settings\Resources\Users\Schemas\UserForm;
use App\Filament\Clusters\Settings\Resources\Users\UserResource;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property User $record
 */
final class EditUser extends EditRecord
{
    use ConfirmsWithTier;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserActions::deactivate(),
            UserActions::reactivate(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'roles' => $this->record->getRoleNames()->all()];
    }

    protected function getSaveFormAction(): Action
    {
        return self::tier2(
            parent::getSaveFormAction()->submit(null),
            heading: fn (): string => __('users.actions.save_heading', ['name' => $this->record->name]),
            rows: fn (): array => ChangeSummary::rows(
                UserForm::summaryLabels(),
                UserForm::summaryValues($this->record),
                UserForm::summaryValues($this->data ?? []),
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
        return DomainActionRunner::run(fn (User $actor): User => app(UpdateStaffUser::class)($actor, $this->record, StaffUserData::fromForm($data)));
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('users.notifications.saved', ['name' => $this->record->name]);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('index');
    }
}
