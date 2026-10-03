<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Pages;

use App\Domain\Members\Actions\UpdateMember;
use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Models\Member;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Schemas\MemberForm;
use App\Filament\Resources\Members\Support\MemberPresenter;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Member $record
 */
final class EditMember extends EditRecord
{
    use ConfirmsWithTier;

    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return MemberForm::fillFrom($this->record->load('nominees'));
    }

    protected function getSaveFormAction(): Action
    {
        return self::tier2(
            parent::getSaveFormAction()->submit(null),
            heading: fn (): string => __('members.actions.save_heading', ['member' => $this->record->displayName()]),
            rows: fn (): array => ChangeSummary::rows(
                MemberPresenter::summaryLabels(),
                MemberPresenter::summaryValues(MemberPresenter::fromMember($this->record->load('nominees'))),
                MemberPresenter::summaryValues(MemberPresenter::dataFromState($this->data)),
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
            fn (User $actor): Member => app(UpdateMember::class)($actor, $this->record, MemberData::fromForm($data)),
        );
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('members.notifications.saved', ['member' => $this->record->displayName()]);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
