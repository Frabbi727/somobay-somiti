<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberApplications;

use App\Domain\Members\Registration\Models\MemberApplication;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Filament\Resources\MemberApplications\Pages\ViewMemberApplication;
use App\Filament\Resources\MemberApplications\Schemas\MemberApplicationInfolist;
use App\Filament\Resources\MemberApplications\Tables\MemberApplicationsTable;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

final class MemberApplicationResource extends Resource
{
    protected static ?string $model = MemberApplication::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Members;

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'mobile';

    public static function getModelLabel(): string
    {
        return __('registration.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('registration.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return MemberApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MemberApplicationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberApplications::route('/'),
            'view' => ViewMemberApplication::route('/{record}'),
        ];
    }
}
