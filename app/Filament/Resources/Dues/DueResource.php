<?php

declare(strict_types=1);

namespace App\Filament\Resources\Dues;

use App\Domain\Contributions\Models\Due;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\Dues\Pages\ListDues;
use App\Filament\Resources\Dues\Tables\DuesTable;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

final class DueResource extends Resource
{
    protected static ?string $model = Due::class;

    protected static ?string $slug = 'dues';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Dues;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('dues.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('dues.plural');
    }

    public static function table(Table $table): Table
    {
        return DuesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDues::route('/'),
        ];
    }
}
