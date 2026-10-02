<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalEntries;

use App\Domain\Accounting\Models\JournalEntry;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Filament\Resources\JournalEntries\Schemas\JournalEntryInfolist;
use App\Filament\Resources\JournalEntries\Tables\JournalEntriesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Posted vouchers: read-only, with reversal as the only write.
 */
final class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static ?string $slug = 'vouchers';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Accounting;

    protected static ?int $navigationSort = 10;

    /**
     * Required by Filament because "Voucher drafts" is nested under this item.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'voucher_no';

    public static function getModelLabel(): string
    {
        return __('journal.voucher.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('journal.voucher.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return JournalEntryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JournalEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalEntries::route('/'),
            'view' => ViewJournalEntry::route('/{record}'),
        ];
    }
}
