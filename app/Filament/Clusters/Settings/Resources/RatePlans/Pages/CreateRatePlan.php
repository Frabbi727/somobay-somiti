<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\RatePlans\Pages;

use App\Domain\Settings\Actions\DraftRatePlan;
use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Models\RatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\RatePlanResource;
use App\Filament\Clusters\Settings\Resources\RatePlans\Support\RatePlanPresenter;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Time\YearMonth;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateRatePlan extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = RatePlanResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return self::tier2(
            parent::getCreateFormAction()->submit(null),
            heading: fn (): string => __('rates.actions.create_heading', [
                'month' => Display::yearMonth(RatePlanPresenter::dataFromState($this->data)->effectiveFrom ?? YearMonth::current()),
            ]),
            rows: fn (): array => ChangeSummary::rows(
                RatePlanPresenter::summaryLabels(),
                [],
                RatePlanPresenter::summaryValues(RatePlanPresenter::dataFromState($this->data)),
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
        return DomainActionRunner::run(
            fn (User $actor): RatePlan => app(DraftRatePlan::class)($actor, RatePlanData::fromForm($data)),
        );
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('rates.notifications.created', ['code' => $this->record instanceof RatePlan ? $this->record->code : '']);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
