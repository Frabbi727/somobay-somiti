<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalDrafts;

use App\Domain\Accounting\Models\JournalDraft;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\JournalDrafts\Pages\CreateJournalDraft;
use App\Filament\Resources\JournalDrafts\Pages\EditJournalDraft;
use App\Filament\Resources\JournalDrafts\Pages\ListJournalDrafts;
use App\Filament\Resources\JournalDrafts\Schemas\JournalDraftForm;
use App\Filament\Resources\JournalDrafts\Tables\JournalDraftsTable;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

final class JournalDraftResource extends Resource
{
    protected static ?string $model = JournalDraft::class;

    protected static ?string $slug = 'voucher-drafts';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Accounting;

    protected static ?int $navigationSort = 20;

    public static function getNavigationParentItem(): string
    {
        return __('journal.voucher.plural');
    }

    public static function getModelLabel(): string
    {
        return __('journal.draft.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('journal.draft.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return JournalDraftForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JournalDraftsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalDrafts::route('/'),
            'create' => CreateJournalDraft::route('/create'),
            'edit' => EditJournalDraft::route('/{record}/edit'),
        ];
    }
}
