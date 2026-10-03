<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\RatePlans\Pages;

use App\Domain\Settings\Actions\UpdateRatePlan;
use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Models\RatePlan;
use App\Filament\Clusters\Settings\Resources\RatePlans\Actions\RatePlanActions;
use App\Filament\Clusters\Settings\Resources\RatePlans\RatePlanResource;
use App\Filament\Clusters\Settings\Resources\RatePlans\Schemas\RatePlanForm;
use App\Filament\Clusters\Settings\Resources\RatePlans\Support\RatePlanPresenter;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property RatePlan $record
 */
final class EditRatePlan extends EditRecord
{
    use ConfirmsWithTier;

    protected static string $resource = RatePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            RatePlanActions::submit(),
            RatePlanActions::delete(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return RatePlanForm::fillFrom($this->record);
    }

    protected function getSaveFormAction(): Action
    {
        return self::tier2(
            parent::getSaveFormAction()->submit(null),
            heading: fn (): string => __('rates.actions.save_heading', ['code' => $this->record->code]),
            rows: fn (): array => ChangeSummary::rows(
                RatePlanPresenter::summaryLabels(),
                RatePlanPresenter::summaryValues(RatePlanData::fromPlan($this->record)),
                RatePlanPresenter::summaryValues(RatePlanPresenter::dataFromState($this->data)),
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
            fn (User $actor): RatePlan => app(UpdateRatePlan::class)($actor, $this->record, RatePlanData::fromForm($data)),
        );
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('rates.notifications.saved', ['code' => $this->record->code]);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
