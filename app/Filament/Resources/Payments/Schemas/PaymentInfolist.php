<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Schemas;

use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Models\PaymentAllocation;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Display;
use App\Support\Money\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

final class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('member.member_no')
                            ->label(__('payments.field.member'))
                            ->formatStateUsing(fn (Payment $record): string => $record->member->displayName()),
                        TextEntry::make('amount_poisha')
                            ->label(__('payments.field.amount'))
                            ->state(fn (Payment $record): string => Display::money($record->amount_poisha))
                            ->weight('bold'),
                        TextEntry::make('method')->label(__('payments.field.method')),
                        TextEntry::make('status')->label(__('payments.field.status'))->badge(),
                        TextEntry::make('received_on')
                            ->label(__('payments.field.received_on'))
                            ->state(fn (Payment $record): string => Display::date($record->received_on)),
                        TextEntry::make('trx_id')->label(__('payments.field.trx_id'))->placeholder('—'),
                        TextEntry::make('recorder.name')->label(__('payments.field.recorded_by')),
                        TextEntry::make('approver.name')->label(__('payments.field.approved_by'))->placeholder('—'),
                        TextEntry::make('journalEntry.voucher_no')
                            ->label(__('payments.field.voucher'))
                            ->url(fn (Payment $record): ?string => $record->journal_entry_id === null ? null : JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id]))
                            ->color('primary')
                            ->placeholder('—'),
                        TextEntry::make('advance_held')
                            ->label(__('payments.field.advance_held'))
                            ->state(fn (Payment $record): string => Display::money(Money::sum($record->advanceEntries
                                ->where('kind', AdvanceEntryKind::PaymentSurplus)
                                ->map(fn ($entry): Money => $entry->delta_poisha)))),
                        TextEntry::make('proof_path')
                            ->label(__('payments.field.proof'))
                            ->formatStateUsing(fn (): string => __('common.view'))
                            ->url(fn (Payment $record): ?string => $record->proof_path === null ? null : Storage::disk('local')->temporaryUrl($record->proof_path, now()->addMinutes(10)), shouldOpenInNewTab: true)
                            ->color('primary')
                            ->placeholder('—'),
                        TextEntry::make('notes')->label(__('payments.field.notes'))->placeholder('—'),
                        TextEntry::make('rejection_reason')->label(__('payments.field.reason'))->visible(fn (Payment $record): bool => $record->rejection_reason !== null),
                        TextEntry::make('reversal_reason')->label(__('payments.field.reason'))->visible(fn (Payment $record): bool => $record->reversal_reason !== null),
                    ]),
                Section::make(__('payments.field.allocations'))
                    ->columnSpanFull()
                    ->visible(fn (Payment $record): bool => $record->allocations->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('allocations')
                            ->hiddenLabel()
                            ->columns(3)
                            ->schema([
                                TextEntry::make('due.month')->hiddenLabel()->state(fn (PaymentAllocation $record): string => Display::yearMonth($record->due->month)),
                                TextEntry::make('due.type')->hiddenLabel()->state(fn (PaymentAllocation $record): string => $record->due->type->getLabel()),
                                TextEntry::make('amount_poisha')->hiddenLabel()->state(fn (PaymentAllocation $record): string => Display::money($record->amount_poisha)),
                            ]),
                    ]),
            ]);
    }
}
