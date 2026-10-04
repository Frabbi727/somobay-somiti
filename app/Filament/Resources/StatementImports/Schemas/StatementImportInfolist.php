<?php

declare(strict_types=1);

namespace App\Filament\Resources\StatementImports\Schemas;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\StatementImport;
use App\Domain\Accounting\Statements\StatementReconciliation;
use App\Filament\Support\Display;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class StatementImportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $summary = fn (StatementImport $record): array => once(fn (): array => app(StatementReconciliation::class)->summary($record));

        return $schema->components([
            Section::make()
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('method')->label(__('statements.field.account'))->badge()->color('gray'),
                    TextEntry::make('filename')->label(__('statements.field.filename')),
                    TextEntry::make('period_from')
                        ->label(__('statements.field.period'))
                        ->state(fn (StatementImport $record): string => Display::date($record->period_from).' – '.Display::date($record->period_to)),
                    TextEntry::make('lines_count')
                        ->label(__('statements.field.lines'))
                        ->state(fn (StatementImport $record): string => Display::digits($record->lines_count).($record->duplicates_skipped > 0 ? ' (+'.Display::digits($record->duplicates_skipped).' '.__('statements.field.duplicates').')' : '')),
                ]),
            Section::make(__('statements.summary.heading'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('statement_balance')
                        ->label(__('statements.summary.statement_balance'))
                        ->state(fn (StatementImport $record): string => $summary($record)['statement_balance'] === null ? __('statements.summary.no_balance') : Display::money($summary($record)['statement_balance'])),
                    TextEntry::make('book_balance')
                        ->label(__('statements.summary.book_balance'))
                        ->state(fn (StatementImport $record): string => Display::money($summary($record)['book_balance'])),
                    TextEntry::make('difference')
                        ->label(__('statements.summary.difference'))
                        ->state(fn (StatementImport $record): string => $summary($record)['difference'] === null ? '—' : Display::money($summary($record)['difference']))
                        ->color(fn (StatementImport $record): string => ($summary($record)['difference']?->isZero() ?? true) ? 'success' : 'danger')
                        ->weight('bold'),
                    TextEntry::make('unmatched')
                        ->label(__('statements.summary.unmatched'))
                        ->state(fn (StatementImport $record): string => Display::digits($summary($record)['unmatched_count']).' · '.Display::money($summary($record)['unmatched_statement'])),
                    TextEntry::make('book_only')
                        ->label(__('statements.summary.book_only'))
                        ->state(fn (StatementImport $record): string => $summary($record)['book_only']->isEmpty()
                            ? '—'
                            : $summary($record)['book_only']->map(fn (JournalLine $line): string => Display::digits($line->entry->voucher_no).' '.Display::money($line->debit_poisha->minus($line->credit_poisha)))->implode(', '))
                        ->columnSpan(2),
                ]),
        ]);
    }
}
