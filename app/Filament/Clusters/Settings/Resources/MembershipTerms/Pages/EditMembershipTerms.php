<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\MembershipTerms\Pages;

use App\Domain\Members\Terms\Actions\SaveTermsDraft;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Actions\MembershipTermsActions;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\MembershipTermsResource;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property MembershipTerms $record
 */
final class EditMembershipTerms extends EditRecord
{
    use ConfirmsWithTier;

    protected static string $resource = MembershipTermsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            MembershipTermsActions::publish(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return self::tier1(
            parent::getSaveFormAction()->submit(null),
            heading: fn (): string => __('terms.admin.save_heading'),
        )
            ->mountUsing(fn () => $this->form->validate())
            ->action(fn () => $this->save());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DomainActionRunner::run(fn (User $actor): MembershipTerms => app(SaveTermsDraft::class)(
            $actor,
            (string) ($data['rules_bn'] ?? ''),
            (string) ($data['rules_en'] ?? ''),
            (string) ($data['declaration_bn'] ?? ''),
            (string) ($data['declaration_en'] ?? ''),
        ));
    }

    protected function getSavedNotificationTitle(): string
    {
        return __('terms.admin.saved');
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
