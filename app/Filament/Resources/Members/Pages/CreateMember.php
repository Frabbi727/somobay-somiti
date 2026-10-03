<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Pages;

use App\Domain\Members\Actions\CreateMember as CreateMemberAction;
use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Models\Member;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Members\Support\MemberPresenter;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Time\YearMonth;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateMember extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = MemberResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return self::tier2(
            parent::getCreateFormAction()->submit(null),
            heading: fn (): string => __('members.actions.create_heading', ['name' => (string) ($this->data['name_bn'] ?? '')]),
            rows: fn (): array => ChangeSummary::rows(
                MemberPresenter::summaryLabels(withShares: true),
                [],
                MemberPresenter::summaryValues(
                    MemberPresenter::dataFromState($this->data),
                    (int) ($this->data['shares'] ?? 0),
                    $this->month(),
                ),
            ),
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
        return DomainActionRunner::run(fn (User $actor): Member => app(CreateMemberAction::class)(
            $actor,
            MemberData::fromForm($data),
            (int) ($data['shares'] ?? 0),
            YearMonth::parse((string) ($data['effective_from'] ?? '')),
        ));
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('members.notifications.created', ['member' => $this->record instanceof Member ? $this->record->displayName() : '']);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }

    private function month(): ?YearMonth
    {
        $value = $this->data['effective_from'] ?? null;

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? YearMonth::parse($value) : null;
    }
}
