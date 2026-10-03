<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members;

use App\Domain\Members\Models\Member;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Members\RelationManagers\DuesRelationManager;
use App\Filament\Resources\Members\RelationManagers\ShareLotsRelationManager;
use App\Filament\Resources\Members\RelationManagers\ShareTransactionsRelationManager;
use App\Filament\Resources\Members\Schemas\MemberForm;
use App\Filament\Resources\Members\Schemas\MemberInfolist;
use App\Filament\Resources\Members\Tables\MembersTable;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

final class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Members;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'member_no';

    public static function getModelLabel(): string
    {
        return __('members.member.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('members.member.plural');
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['member_no', 'name_bn', 'name_en', 'mobile'];
    }

    public static function form(Schema $schema): Schema
    {
        return MemberForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MemberInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ShareLotsRelationManager::class,
            ShareTransactionsRelationManager::class,
            DuesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'view' => ViewMember::route('/{record}'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }
}
