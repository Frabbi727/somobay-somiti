<?php

declare(strict_types=1);

namespace App\Filament\Resources\Investments\Schemas;

use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentLedgerEntry;
use App\Domain\Investments\Models\InvestmentReturnExpectation;
use App\Domain\Investments\Services\ExpectedReturns;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Support\Display;
use App\Filament\Support\HistorySection;
use App\Support\Money\Bps;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use WeakMap;

/**
 * @phpstan-import-type ReturnSummary from ExpectedReturns
 */
final class InvestmentInfolist
{
    /** @var WeakMap<Investment, ReturnSummary>|null */
    private static ?WeakMap $summaries = null;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('investment_no')->label(__('investments.field.number'))->weight('bold'),
                    TextEntry::make('status')->label(__('investments.field.status'))->badge(),
                    TextEntry::make('type')->label(__('investments.field.type'))->badge()->color('gray'),
                    TextEntry::make('name')->label(__('investments.field.name'))->placeholder('—'),
                    TextEntry::make('institution')->label(__('investments.field.institution')),
                    TextEntry::make('instrument_no')->label(__('investments.field.instrument_no'))->placeholder('—'),
                    TextEntry::make('funded_from')->label(__('investments.field.funded_from'))->badge()->color('gray'),
                    TextEntry::make('principal_poisha')
                        ->label(__('investments.field.principal'))
                        ->formatStateUsing(fn (Investment $record): string => Display::money($record->principal_poisha)),
                    TextEntry::make('total_invested')
                        ->label(__('investments.field.total_invested'))
                        ->state(fn (Investment $record): string => Display::money($record->totalInvested()))
                        ->visible(fn (Investment $record): bool => $record->ledger->isNotEmpty()),
                    TextEntry::make('book_value')
                        ->label(__('investments.field.book_value'))
                        ->state(fn (Investment $record): string => Display::money($record->bookValue()))
                        ->weight('bold'),
                    TextEntry::make('expected_rate_bps')
                        ->label(__('investments.field.expected_rate'))
                        ->state(fn (Investment $record): ?string => $record->expected_rate_bps === null ? null : Bps::of($record->expected_rate_bps)->format(app()->getLocale()))
                        ->placeholder('—'),
                    TextEntry::make('invested_on')
                        ->label(__('investments.field.invested_on'))
                        ->formatStateUsing(fn (Investment $record): string => Display::date($record->invested_on)),
                    TextEntry::make('matures_on')
                        ->label(__('investments.field.matures_on'))
                        ->state(fn (Investment $record): ?string => $record->matures_on === null ? null : Display::date($record->matures_on))
                        ->placeholder('—'),
                    TextEntry::make('closed_on')
                        ->label(__('investments.field.date'))
                        ->state(fn (Investment $record): ?string => $record->closed_on === null ? null : Display::date($record->closed_on))
                        ->visible(fn (Investment $record): bool => $record->closed_on !== null),
                    TextEntry::make('sale_price_poisha')
                        ->label(__('investments.field.sale_price'))
                        ->state(fn (Investment $record): ?string => $record->sale_price_poisha === null ? null : Display::money($record->sale_price_poisha))
                        ->visible(fn (Investment $record): bool => $record->sale_price_poisha !== null),
                    TextEntry::make('sale_expenses_poisha')
                        ->label(__('investments.field.sale_expenses'))
                        ->state(fn (Investment $record): ?string => $record->sale_expenses_poisha === null ? null : Display::money($record->sale_expenses_poisha))
                        ->visible(fn (Investment $record): bool => $record->sale_price_poisha !== null),
                    TextEntry::make('sale_gain_poisha')
                        ->label(__('investments.field.sale_gain'))
                        ->state(fn (Investment $record): ?string => $record->sale_gain_poisha === null ? null : Display::money($record->sale_gain_poisha))
                        ->color(fn (Investment $record): string => $record->sale_gain_poisha?->isNegative() === true ? 'danger' : 'success')
                        ->weight('bold')
                        ->visible(fn (Investment $record): bool => $record->sale_gain_poisha !== null),
                    TextEntry::make('resolution.resolution_no')
                        ->label(__('investments.field.resolution'))
                        ->state(fn (Investment $record): ?string => $record->resolution?->displayName())
                        ->placeholder('—'),
                    TextEntry::make('limit_warnings')
                        ->label(__('investments.field.warnings'))
                        ->color('warning')
                        ->visible(fn (Investment $record): bool => $record->limit_warnings !== null)
                        ->columnSpanFull(),
                    TextEntry::make('notes')->label(__('investments.field.notes'))->placeholder('—')->columnSpanFull(),
                    TextEntry::make('attachment_path')
                        ->label(__('investments.field.attachment'))
                        ->formatStateUsing(fn (): string => __('common.view'))
                        ->url(fn (Investment $record): ?string => $record->attachment_path === null ? null : Storage::disk('local')->temporaryUrl($record->attachment_path, now()->addMinutes(10)), shouldOpenInNewTab: true)
                        ->color('primary')
                        ->placeholder('—'),
                    TextEntry::make('recorder.name')->label(__('investments.field.recorded_by')),
                    TextEntry::make('approver.name')->label(__('investments.field.approved_by'))->placeholder('—'),
                    TextEntry::make('reversal_reason')
                        ->label(__('investments.field.reason'))
                        ->color('danger')
                        ->visible(fn (Investment $record): bool => $record->reversal_reason !== null),
                    TextEntry::make('rejection_reason')->label(__('investments.field.reason'))->visible(fn (Investment $record): bool => $record->rejection_reason !== null),
                ]),
            Section::make(__('investments.returns.title'))
                ->description(__('investments.returns.tracking_only'))
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (Investment $record): bool => $record->returnExpectations->isNotEmpty())
                ->schema([
                    TextEntry::make('returns_current')
                        ->label(__('investments.returns.field.current'))
                        ->state(fn (Investment $record): string => self::current(self::summary($record)['current'])),
                    TextEntry::make('returns_expected')
                        ->label(__('investments.returns.field.expected_to_date'))
                        ->state(fn (Investment $record): string => Display::money(self::summary($record)['expected'])),
                    TextEntry::make('returns_received')
                        ->label(__('investments.returns.field.received'))
                        ->state(fn (Investment $record): string => Display::money(self::summary($record)['received'])),
                    TextEntry::make('returns_difference')
                        ->label(__('investments.returns.field.difference'))
                        ->state(fn (Investment $record): string => Display::money(self::summary($record)['difference']))
                        ->color(fn (Investment $record): string => self::summary($record)['difference']->isNegative() ? 'danger' : 'success')
                        ->weight('bold'),
                    TextEntry::make('returns_last_received')
                        ->label(__('investments.returns.field.last_received'))
                        ->state(fn (Investment $record): string => Display::date(self::summary($record)['last_received_on'])),
                    TextEntry::make('returns_next_due')
                        ->label(__('investments.returns.field.next_due'))
                        ->state(fn (Investment $record): string => ($next = self::summary($record)['next_due']) === null ? '—' : Display::date($next['due_on']).' · '.Display::money($next['amount'])),
                    RepeatableEntry::make('returnExpectations')
                        ->label(__('investments.returns.history'))
                        ->columnSpanFull()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('starts_on')->hiddenLabel()->state(fn (InvestmentReturnExpectation $record): string => __('investments.returns.from', ['date' => Display::date($record->starts_on)])),
                            TextEntry::make('frequency')->hiddenLabel()->badge(),
                            TextEntry::make('amount_poisha')->hiddenLabel()->state(fn (InvestmentReturnExpectation $record): string => $record->amount_poisha->isZero() ? '—' : Display::money($record->amount_poisha)),
                            TextEntry::make('notes')->hiddenLabel()->state(fn (InvestmentReturnExpectation $record): string => trim(($record->notes ?? '').' · '.($record->creator->name ?? ''), ' ·')),
                        ]),
                ]),
            Section::make(__('investments.register'))
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('ledger')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('kind')->hiddenLabel(),
                            TextEntry::make('journalEntry.voucher_no')
                                ->hiddenLabel()
                                ->formatStateUsing(fn (string $state): string => Display::digits($state))
                                ->url(fn (InvestmentLedgerEntry $record): string => JournalEntryResource::getUrl('view', ['record' => $record->journal_entry_id])),
                            TextEntry::make('journalEntry.entry_date')
                                ->hiddenLabel()
                                ->state(fn (InvestmentLedgerEntry $record): string => Display::date($record->journalEntry->entry_date)),
                            TextEntry::make('delta_poisha')
                                ->hiddenLabel()
                                ->formatStateUsing(fn (InvestmentLedgerEntry $record): string => Display::money($record->delta_poisha)),
                        ])
                        ->placeholder('—'),
                ]),
            HistorySection::make(),
        ]);
    }

    /**
     * @return ReturnSummary
     */
    private static function summary(Investment $record): array
    {
        self::$summaries ??= new WeakMap;

        return self::$summaries[$record] ??= app(ExpectedReturns::class)->summary($record, CarbonImmutable::now(YearMonth::TIMEZONE));
    }

    private static function current(?InvestmentReturnExpectation $row): string
    {
        if ($row === null) {
            return '—';
        }

        return $row->amount_poisha->isZero() ? $row->frequency->getLabel() : Display::money($row->amount_poisha).' · '.$row->frequency->getLabel();
    }
}
