<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberExits\Schemas;

use App\Domain\Exits\Data\ExitPreview;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Exits\Models\MemberExitPayout;
use App\Domain\Exits\Models\MemberExitPayoutReversal;
use App\Domain\Exits\Services\ExitCalculator;
use App\Filament\Support\Display;
use App\Filament\Support\HistorySection;
use App\Support\Money\Bps;
use App\Support\Money\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MemberExitInfolist
{
    public static function configure(Schema $schema): Schema
    {
        // Before approval the figures are a live preview; afterwards the approved snapshot.
        $figure = fn (string $field, string $label, string $preview): TextEntry => TextEntry::make($field)
            ->label($label)
            ->state(function (MemberExit $record) use ($field, $preview): string {
                $value = $record->status === ExitStatus::Requested ? self::preview($record)->{$preview} : $record->{$field};

                return Display::money($value instanceof Money ? $value : Money::zero());
            });

        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('exit_no')->label(__('exits.field.number'))->weight('bold'),
                    TextEntry::make('member.member_no')->label(__('exits.field.member'))->formatStateUsing(fn (MemberExit $record): string => $record->member->displayName()),
                    TextEntry::make('status')->label(__('exits.field.status'))->badge(),
                    TextEntry::make('reason_type')->label(__('exits.field.reason_type'))->badge()->color('gray'),
                    TextEntry::make('exit_month')->label(__('exits.field.exit_month'))->formatStateUsing(fn (MemberExit $record): string => Display::yearMonth($record->exit_month)),
                    TextEntry::make('reason')->label(__('exits.field.reason')),
                ]),
            Section::make(__('exits.register'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    $figure('savings_poisha', __('exits.field.savings'), 'savings'),
                    $figure('advance_poisha', __('exits.field.advance'), 'advance'),
                    $figure('dividends_poisha', __('exits.field.dividends'), 'dividends'),
                    $figure('receivables_poisha', __('exits.field.receivables'), 'receivables'),
                    TextEntry::make('exit_fee_poisha')->label(__('exits.field.exit_fee'))->formatStateUsing(fn (MemberExit $record): string => Display::money($record->exit_fee_poisha)),
                    TextEntry::make('net_poisha')
                        ->label(__('exits.field.net'))
                        ->state(fn (MemberExit $record): string => Display::money($record->net_poisha ?? self::preview($record)->net()))
                        ->weight('bold'),
                ]),
            Section::make(__('exits.field.payee'))
                ->columnSpanFull()
                ->visible(fn (MemberExit $record): bool => $record->payouts->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('payouts')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('payee')->hiddenLabel(),
                            TextEntry::make('share_bps')->hiddenLabel()->formatStateUsing(fn (int $state): string => Bps::of($state)->format(app()->getLocale())),
                            TextEntry::make('amount_poisha')
                                ->hiddenLabel()
                                ->formatStateUsing(fn (MemberExitPayout $record): string => Display::money($record->amount_poisha))
                                ->badge(fn (MemberExitPayout $record): bool => self::isReversed($record))
                                ->color(fn (MemberExitPayout $record): ?string => self::isReversed($record) ? 'danger' : null)
                                ->suffix(fn (MemberExitPayout $record): string => self::isReversed($record) ? ' · '.__('exits.payout_reversal.reversed') : ''),
                        ]),
                ]),
            Section::make(__('exits.payout_reversal.title'))
                ->columnSpanFull()
                ->visible(fn (MemberExit $record): bool => $record->payoutReversals->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('payoutReversals')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('reversalEntry.voucher_no')->hiddenLabel()->formatStateUsing(fn (string $state): string => Display::digits($state)),
                            TextEntry::make('amount_poisha')->hiddenLabel()->formatStateUsing(fn (MemberExitPayoutReversal $record): string => Display::money($record->amount_poisha)),
                            TextEntry::make('reverser.name')->hiddenLabel()->prefix(__('exits.payout_reversal.by').': '),
                            TextEntry::make('reason')->hiddenLabel(),
                        ]),
                ]),
            HistorySection::make(),
        ]);
    }

    private static function isReversed(MemberExitPayout $payout): bool
    {
        return $payout->payout_journal_entry_id !== null
            && MemberExitPayoutReversal::query()->where('payout_journal_entry_id', $payout->payout_journal_entry_id)->exists();
    }

    public static function preview(MemberExit $exit): ExitPreview
    {
        return once(fn (): ExitPreview => app(ExitCalculator::class)->preview($exit->member, $exit->exit_month, $exit->exit_fee_poisha));
    }
}
