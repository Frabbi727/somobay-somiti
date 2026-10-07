<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberApplications\Actions;

use App\Domain\Members\Actions\SetPortalPassword;
use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Actions\InviteMember;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Services\ShareChanger;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Contact\MobileNumber;
use App\Support\Time\YearMonth;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

final class RegistrationActions
{
    use ConfirmsWithTier;

    /**
     * T2 (the form is the summary): mobile + password only; the member fills in the rest.
     */
    public static function invite(): Action
    {
        return self::tier2InForm(Action::make('invite')
            ->label(__('registration.actions.invite'))
            ->tooltip(__('registration.actions.invite'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('primary')
            ->button()
            ->labeledFrom('md')
            ->visible(fn (): bool => (bool) auth()->user()?->can('create', MemberApplication::class))
            ->modalHeading(__('registration.actions.invite_heading'))
            ->modalDescription(__('registration.actions.invite_description'))
            ->schema([
                TextInput::make('mobile')
                    ->label(__('registration.field.mobile'))
                    ->tel()
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (MobileNumber::normalize(is_string($value) ? $value : null) === null) {
                            $fail(__('members.errors.mobile_format'));
                        }
                    }),
                TextInput::make('password')
                    ->label(__('registration.field.password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(SetPortalPassword::MIN_LENGTH)
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label(__('registration.field.password_confirmation'))
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $application = DomainActionRunner::run(fn (User $actor): MemberApplication => app(InviteMember::class)($actor, (string) $data['mobile'], (string) $data['password']));

                Notification::make()
                    ->title(__('registration.notifications.invited', ['mobile' => Display::digits($application->mobile)]))
                    ->success()
                    ->persistent()
                    ->send();
            }));
    }

    public static function approve(): Action
    {
        $final = fn (MemberApplication $record): bool => $record->isLastStep();

        $action = Action::make('approve')
            ->label(__('registration.actions.approve'))
            ->tooltip(__('registration.actions.approve'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('decide')
            ->action(function (MemberApplication $record, array $data): void {
                $from = is_string($data['effective_from'] ?? null) && $data['effective_from'] !== '' ? YearMonth::parse($data['effective_from']) : null;
                $shares = isset($data['shares']) && $data['shares'] !== '' ? (int) $data['shares'] : null;

                $result = DomainActionRunner::run(fn (User $actor): MemberApplication => app(DecideRegistration::class)($actor, $record, RegistrationDecisionType::Approve, null, $shares, $from));

                Notification::make()
                    ->title($result->status === MemberApplicationStatus::Approved
                        ? __('registration.notifications.approved_final', ['name' => self::name($result), 'member_no' => (string) $result->member()->value('member_no')])
                        : __('registration.notifications.approved_step', ['role' => $result->currentRole()?->getLabel() ?? '']))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (MemberApplication $record): string => __('registration.actions.approve_heading', ['name' => self::name($record)]),
            expected: fn (MemberApplication $record): string => $record->mobile,
            submitLabel: fn (MemberApplication $record): string => __('registration.actions.approve_submit', ['mobile' => $record->mobile]),
            description: fn (MemberApplication $record): ?string => $record->isLastStep() ? (string) __('registration.actions.approve_final_description') : null,
            fields: [
                TextInput::make('shares')
                    ->label(__('registration.field.shares'))
                    ->integer()
                    ->minValue(1)
                    ->default(fn (MemberApplication $record): ?int => $record->requested_shares)
                    ->required($final)
                    ->visible($final)
                    ->live(onBlur: true),
                TextInput::make('effective_from')
                    ->label(__('registration.field.effective_from'))
                    ->type('month')
                    ->regex('/^\d{4}-\d{2}$/')
                    ->default(fn (): string => (string) YearMonth::current())
                    ->required($final)
                    ->visible($final)
                    ->live(onBlur: true),
                TextEntry::make('fee_summary')
                    ->hiddenLabel()
                    ->state(fn (Get $get): string => self::feeSummary($get('shares'), $get('effective_from')))
                    ->weight('bold')
                    ->visible($final),
            ],
        );
    }

    public static function sendBack(): Action
    {
        $action = Action::make('sendBack')
            ->label(__('registration.actions.send_back'))
            ->tooltip(__('registration.actions.send_back'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->authorize('decide')
            ->action(function (MemberApplication $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): MemberApplication => app(DecideRegistration::class)($actor, $record, RegistrationDecisionType::Return, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('registration.notifications.sent_back', ['name' => self::name($record)]))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (MemberApplication $record): string => __('registration.actions.send_back_heading', ['name' => self::name($record)]),
            expected: fn (MemberApplication $record): string => $record->mobile,
            submitLabel: fn (MemberApplication $record): string => __('registration.actions.send_back_submit', ['mobile' => $record->mobile]),
            fields: [Textarea::make('reason')->label(__('registration.field.reason'))->required()->minLength(DecideRegistration::MIN_REASON)->rows(2)],
        );
    }

    public static function reject(): Action
    {
        $action = Action::make('reject')
            ->label(__('registration.actions.reject'))
            ->tooltip(__('registration.actions.reject'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('decide')
            ->action(function (MemberApplication $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): MemberApplication => app(DecideRegistration::class)($actor, $record, RegistrationDecisionType::Reject, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('registration.notifications.rejected', ['name' => self::name($record)]))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (MemberApplication $record): string => __('registration.actions.reject_heading', ['name' => self::name($record)]),
            expected: fn (MemberApplication $record): string => $record->mobile,
            submitLabel: fn (MemberApplication $record): string => __('registration.actions.reject_submit', ['mobile' => $record->mobile]),
            description: __('registration.actions.reject_description'),
            fields: [Textarea::make('reason')->label(__('registration.field.reason'))->required()->minLength(DecideRegistration::MIN_REASON)->rows(2)],
        );
    }

    /**
     * "Registration fee due: ৳ 300.00" for the shares and month chosen on the final approval.
     */
    public static function feeSummary(mixed $shares, mixed $from): string
    {
        $count = is_numeric($shares) ? (int) $shares : 0;

        if (! is_string($from) || preg_match('/^\d{4}-\d{2}$/', $from) !== 1 || $count < 1) {
            return '';
        }

        try {
            $fee = app(ShareChanger::class)->planFor(YearMonth::parse($from))->registration_fee_per_share_poisha->multipliedByInt($count);

            return __('members.actions.registration_fee_summary', ['amount' => Display::money($fee)]);
        } catch (\Throwable $exception) {
            return $exception->getMessage();
        }
    }

    private static function name(MemberApplication $application): string
    {
        return (app()->getLocale() === 'bn' ? $application->name_bn : $application->name_en) ?? $application->mobile;
    }
}
