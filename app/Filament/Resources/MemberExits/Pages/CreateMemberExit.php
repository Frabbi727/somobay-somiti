<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberExits\Pages;

use App\Domain\Exits\Actions\RequestExit;
use App\Domain\Exits\Enums\ExitReason;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Members\Models\Member;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Resources\MemberExits\MemberExitResource;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateMemberExit extends CreateRecord
{
    use ConfirmsWithTier;

    protected static string $resource = MemberExitResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('exits.actions.request');
    }

    protected function getCreateFormAction(): Action
    {
        return self::tier2(
            parent::getCreateFormAction()->submit(null)->label(__('exits.actions.request')),
            heading: fn (): string => __('exits.actions.request_heading', ['member' => (string) Member::query()->find((int) ($this->data['member_id'] ?? 0))?->displayName()]),
            rows: fn (): array => ChangeSummary::rows(
                ['reason_type' => __('exits.field.reason_type'), 'exit_month' => __('exits.field.exit_month'), 'exit_fee' => __('exits.field.exit_fee')],
                [],
                [
                    'reason_type' => ExitReason::tryFrom((string) ($this->data['reason_type'] ?? '')),
                    'exit_month' => $this->data['exit_month'] ?? null,
                    'exit_fee' => '৳ '.($this->data['exit_fee'] ?? '0'),
                ],
            ),
            description: __('exits.actions.request_description'),
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
        $reason = $data['reason_type'] ?? null;
        $fee = $data['exit_fee'] ?? null;

        return DomainActionRunner::run(fn (User $actor): MemberExit => app(RequestExit::class)(
            $actor,
            Member::query()->findOrFail((int) ($data['member_id'] ?? 0)),
            $reason instanceof ExitReason ? $reason : ExitReason::from((string) $reason),
            (string) ($data['reason'] ?? ''),
            YearMonth::parse((string) ($data['exit_month'] ?? '')),
            $fee instanceof Money ? $fee : Money::zero(),
            is_numeric($data['resolution_id'] ?? null) ? (int) $data['resolution_id'] : null,
        ));
    }

    protected function getCreatedNotificationTitle(): string
    {
        return __('exits.notifications.requested', ['number' => $this->record instanceof MemberExit ? $this->record->exit_no : '']);
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
