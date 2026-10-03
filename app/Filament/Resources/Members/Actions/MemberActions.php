<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Actions;

use App\Domain\Members\Actions\ChangeShares;
use App\Domain\Members\Actions\DeactivateMember;
use App\Domain\Members\Actions\ReactivateMember;
use App\Domain\Members\Enums\ShareChangeType;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Services\ShareChanger;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Time\YearMonth;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

final class MemberActions
{
    use ConfirmsWithTier;

    /**
     * T2: the form shows a live summary of the result and the registration fee before saving.
     */
    public static function changeShares(): Action
    {
        return Action::make('changeShares')
            ->label(__('members.actions.change_shares'))
            ->tooltip(__('members.actions.change_shares'))
            ->icon(Heroicon::OutlinedSquare3Stack3d)
            ->color('primary')
            ->authorize('changeShares')
            ->modalHeading(fn (Member $record): string => __('members.actions.change_shares_heading', ['member' => $record->displayName()]))
            ->schema([
                Select::make('type')
                    ->label(__('members.actions.change_type'))
                    ->options(ShareChangeType::class)
                    ->default(ShareChangeType::Increase->value)
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('count')
                    ->label(__('members.actions.change_count'))
                    ->integer()
                    ->minValue(1)
                    ->default(1)
                    ->required()
                    ->live(onBlur: true),
                TextInput::make('from')
                    ->label(__('members.actions.change_from'))
                    ->type('month')
                    ->regex('/^\d{4}-\d{2}$/')
                    ->default(fn (): string => (string) YearMonth::current()->next())
                    ->required()
                    ->live(onBlur: true),
                Textarea::make('reason')->label(__('members.actions.change_reason'))->rows(2),
                TextEntry::make('summary')
                    ->hiddenLabel()
                    ->state(fn (Get $get, Member $record): string => self::changeSummary($record, $get('type'), (int) $get('count'), $get('from')))
                    ->weight('bold'),
            ])
            ->action(function (Member $record, array $data): void {
                $from = YearMonth::parse((string) $data['from']);
                $delta = self::delta($data['type'] ?? null, (int) ($data['count'] ?? 0));

                DomainActionRunner::run(fn (User $actor): Member => app(ChangeShares::class)($actor, $record, $delta, $from, self::text($data['reason'] ?? null)));

                Notification::make()
                    ->title(__('members.notifications.shares_changed', [
                        'member' => $record->displayName(),
                        'shares' => Display::digits($record->sharesIn($from)),
                        'month' => Display::yearMonth($from),
                    ]))
                    ->success()
                    ->send();
            });
    }

    public static function deactivate(): Action
    {
        $action = Action::make('deactivate')
            ->label(__('members.actions.deactivate'))
            ->tooltip(__('members.actions.deactivate'))
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('danger')
            ->authorize('deactivate')
            ->action(function (Member $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): Member => app(DeactivateMember::class)($actor, $record, (string) ($data['reason'] ?? '')));

                Notification::make()->title(__('members.notifications.deactivated', ['member' => $record->displayName()]))->success()->send();
            });

        return self::tier3(
            $action,
            heading: fn (Member $record): string => __('members.actions.deactivate_heading', ['member' => $record->displayName()]),
            expected: fn (Member $record): string => $record->member_no,
            submitLabel: fn (Member $record): string => __('members.actions.deactivate_submit', ['member' => $record->member_no]),
            description: __('members.actions.deactivate_description'),
            fields: [
                Textarea::make('reason')->label(__('members.actions.reason'))->required()->minLength(5)->rows(2),
            ],
        );
    }

    public static function reactivate(): Action
    {
        $action = Action::make('reactivate')
            ->label(__('members.actions.reactivate'))
            ->tooltip(__('members.actions.reactivate'))
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->authorize('reactivate')
            ->action(function (Member $record): void {
                DomainActionRunner::run(fn (User $actor): Member => app(ReactivateMember::class)($actor, $record));

                Notification::make()->title(__('members.notifications.reactivated', ['member' => $record->displayName()]))->success()->send();
            });

        return self::tier1(
            $action,
            heading: fn (Member $record): string => __('members.actions.reactivate_heading', ['member' => $record->displayName()]),
        );
    }

    /**
     * "1 → 3 shares from November 2026 · Registration fee due: ৳ 200.00".
     */
    public static function changeSummary(Member $member, mixed $type, int $count, mixed $from): string
    {
        if (! is_string($from) || preg_match('/^\d{4}-\d{2}$/', $from) !== 1 || $count < 1) {
            return '';
        }

        $month = YearMonth::parse($from);
        $current = $member->sharesIn($month);
        $delta = self::delta($type, $count);

        $summary = __('members.actions.change_summary', [
            'current' => Display::digits($current),
            'after' => Display::digits(max(0, $current + $delta)),
            'month' => Display::yearMonth($month),
        ]);

        if ($delta > 0) {
            try {
                $fee = app(ShareChanger::class)->planFor($month)->registration_fee_per_share_poisha->multipliedByInt($delta);
                $summary .= ' · '.__('members.actions.registration_fee_summary', ['amount' => Display::money($fee)]);
            } catch (\Throwable $exception) {
                $summary .= ' · '.$exception->getMessage();
            }
        }

        return $summary;
    }

    private static function delta(mixed $type, int $count): int
    {
        $type = $type instanceof ShareChangeType ? $type : ShareChangeType::tryFrom(is_string($type) ? $type : '');

        return $type === ShareChangeType::Decrease ? -$count : $count;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
