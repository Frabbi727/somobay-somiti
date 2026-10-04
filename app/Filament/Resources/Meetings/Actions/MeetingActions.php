<?php

declare(strict_types=1);

namespace App\Filament\Resources\Meetings\Actions;

use App\Domain\Governance\Actions\CancelMeeting;
use App\Domain\Governance\Actions\DecideResolution;
use App\Domain\Governance\Actions\HoldMeeting;
use App\Domain\Governance\Actions\ProposeResolution;
use App\Domain\Governance\Actions\RecordAttendance;
use App\Domain\Governance\Actions\WithdrawResolution;
use App\Domain\Governance\Data\ResolutionData;
use App\Domain\Governance\Enums\Majority;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\Quorum;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Enums\Role;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Meeting and resolution actions. Governance records change no money, so most are T1; marking a
 * meeting held shows the quorum it fixes (T2), and recording a vote is typed (T3).
 */
final class MeetingActions
{
    use ConfirmsWithTier;

    public static function attendance(): Action
    {
        $action = Action::make('attendance')
            ->label(__('governance.actions.attendance'))
            ->tooltip(__('governance.actions.attendance'))
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('info')
            ->authorize('update')
            ->fillForm(fn (Meeting $record): array => ['ids' => $record->attendees()->pluck($record->type->isOfMembers() ? 'member_id' : 'user_id')->all()])
            ->schema(fn (Meeting $record): array => [
                $record->type->isOfMembers()
                    ? Select::make('ids')
                        ->label(__('governance.field.attendees'))
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Member::query()
                            ->where('status', MemberStatus::Active)
                            ->where(fn ($query) => $query
                                ->where('member_no', 'ilike', "%{$search}%")
                                ->orWhere('name_en', 'ilike', "%{$search}%")
                                ->orWhere('name_bn', 'ilike', "%{$search}%"))
                            ->orderBy('member_no')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Member $member): array => [$member->id => $member->displayName()])
                            ->all())
                        ->getOptionLabelsUsing(fn (array $values): array => Member::query()->whereIn('id', $values)->get()
                            ->mapWithKeys(fn (Member $member): array => [$member->id => $member->displayName()])->all())
                    : CheckboxList::make('ids')
                        ->label(__('governance.field.attendees'))
                        ->options(fn (): array => User::query()
                            ->role(array_map(fn (Role $role): string => $role->value, Quorum::COMMITTEE_ROLES))
                            ->whereNull('deactivated_at')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->columns(2),
            ])
            ->action(function (Meeting $record, array $data): void {
                $ids = array_values(array_map('intval', (array) ($data['ids'] ?? [])));
                DomainActionRunner::run(fn (User $actor): Meeting => app(RecordAttendance::class)($actor, $record, $ids));
                Notification::make()->title(__('governance.notifications.attendance', ['count' => Display::digits(count($ids))]))->success()->send();
            });

        return self::tier1($action, fn (Meeting $record): string => __('governance.actions.attendance_heading', ['number' => $record->meeting_no]));
    }

    public static function hold(): Action
    {
        $action = Action::make('hold')
            ->label(__('governance.actions.hold'))
            ->tooltip(__('governance.actions.hold'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->authorize('hold')
            ->schema([Textarea::make('minutes')->label(__('governance.field.minutes'))->rows(6)])
            ->action(function (Meeting $record, array $data): void {
                $held = DomainActionRunner::run(fn (User $actor): Meeting => app(HoldMeeting::class)($actor, $record, $data['minutes'] ?? null));
                Notification::make()
                    ->title(__('governance.notifications.held', ['met' => __($held->quorum_met ? 'governance.notifications.quorum_met' : 'governance.notifications.quorum_missed')]))
                    ->color($held->quorum_met ? 'success' : 'warning')
                    ->send();
            });

        return self::tier2(
            $action,
            heading: fn (Meeting $record): string => __('governance.actions.hold_heading', ['number' => $record->meeting_no]),
            rows: function (Meeting $record): array {
                $quorum = app(Quorum::class);
                $eligible = $quorum->eligible($record->type);

                return ChangeSummary::rows(
                    ['quorum' => __('governance.field.quorum')],
                    [],
                    ['quorum' => __('governance.field.quorum_value', [
                        'present' => Display::digits($record->attendees()->count()),
                        'eligible' => Display::digits($eligible),
                        'required' => Display::digits($quorum->required($record->type, $eligible)),
                    ])],
                );
            },
            description: __('governance.actions.hold_description'),
            showOld: false,
        );
    }

    public static function cancel(): Action
    {
        $action = Action::make('cancel')
            ->label(__('governance.actions.cancel'))
            ->tooltip(__('governance.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('cancel')
            ->schema([Textarea::make('reason')->label(__('governance.field.cancel_reason'))->required()->minLength(5)->rows(2)])
            ->action(function (Meeting $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): Meeting => app(CancelMeeting::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('governance.notifications.cancelled'))->success()->send();
            });

        return self::tier1($action, fn (Meeting $record): string => __('governance.actions.cancel_heading', ['number' => $record->meeting_no]));
    }

    public static function propose(): Action
    {
        $action = Action::make('propose')
            ->label(__('governance.actions.propose'))
            ->tooltip(__('governance.actions.propose'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->visible(fn (Meeting $record): bool => Gate::allows('create', [Resolution::class, $record]))
            ->schema([
                Select::make('subject')->label(__('governance.field.subject'))->options(ResolutionSubject::class)->required()->native(false),
                Select::make('majority')->label(__('governance.field.majority'))->options(Majority::class)->default(Majority::Simple->value)->required()->native(false),
                TextInput::make('title')->label(__('governance.field.title'))->required()->minLength(3)->maxLength(255),
                Textarea::make('body')->label(__('governance.field.body'))->required()->minLength(3)->rows(4),
            ])
            ->action(function (Meeting $record, array $data): void {
                $resolution = DomainActionRunner::run(fn (User $actor): Resolution => app(ProposeResolution::class)($actor, $record, ResolutionData::fromForm($data)));
                Notification::make()->title(__('governance.notifications.proposed', ['number' => $resolution->resolution_no]))->success()->send();
            });

        return self::tier1($action, fn (Meeting $record): string => __('governance.actions.propose_heading', ['number' => $record->meeting_no]));
    }

    public static function decide(): Action
    {
        $votes = fn (string $name, string $label): TextInput => TextInput::make($name)->label($label)->integer()->minValue(0)->default(0)->required();

        $action = Action::make('decide')
            ->label(__('governance.actions.decide'))
            ->tooltip(__('governance.actions.decide'))
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('success')
            ->authorize('decide')
            ->action(function (Resolution $record, array $data): void {
                $decided = DomainActionRunner::run(fn (User $actor): Resolution => app(DecideResolution::class)(
                    $actor,
                    $record,
                    (int) ($data['votes_for'] ?? 0),
                    (int) ($data['votes_against'] ?? 0),
                    (int) ($data['votes_abstain'] ?? 0),
                ));
                Notification::make()
                    ->title(__('governance.notifications.decided', ['number' => $decided->resolution_no, 'status' => $decided->status->getLabel()]))
                    ->color($decided->status === ResolutionStatus::Passed ? 'success' : 'warning')
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (Resolution $record): string => __('governance.actions.decide_heading', ['number' => $record->resolution_no]),
            expected: fn (Resolution $record): string => $record->resolution_no,
            submitLabel: __('governance.actions.decide_submit'),
            description: fn (Resolution $record): string => $record->title.' — '.$record->majority->getLabel(),
            fields: [
                $votes('votes_for', __('governance.field.votes_for')),
                $votes('votes_against', __('governance.field.votes_against')),
                $votes('votes_abstain', __('governance.field.votes_abstain')),
            ],
        );
    }

    public static function withdraw(): Action
    {
        $action = Action::make('withdraw')
            ->label(__('governance.actions.withdraw'))
            ->tooltip(__('governance.actions.withdraw'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->authorize('withdraw')
            ->action(function (Resolution $record): void {
                DomainActionRunner::run(fn (User $actor): Resolution => app(WithdrawResolution::class)($actor, $record));
                Notification::make()->title(__('governance.notifications.withdrawn'))->success()->send();
            });

        return self::tier1($action, fn (Resolution $record): string => __('governance.actions.withdraw_heading', ['number' => $record->resolution_no]));
    }
}
