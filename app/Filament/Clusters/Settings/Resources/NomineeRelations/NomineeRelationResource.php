<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\NomineeRelations;

use App\Domain\Members\Actions\SaveNomineeRelation;
use App\Domain\Members\Data\NomineeRelationData;
use App\Domain\Members\Models\NomineeRelation;
use App\Filament\Clusters\Settings\Resources\NomineeRelations\Pages\ManageNomineeRelations;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class NomineeRelationResource extends Resource
{
    protected static ?string $model = NomineeRelation::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getModelLabel(): string
    {
        return __('members.relation.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('members.relation.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('key')->label(__('members.relation.key'))->helperText(__('members.relation.key_help'))->required()->regex('/^[a-z_]{2,30}$/')->maxLength(30),
            TextInput::make('sort')->label(__('members.relation.sort'))->integer()->default(0),
            TextInput::make('label_bn')->label(__('members.relation.label_bn'))->required()->maxLength(50),
            TextInput::make('label_en')->label(__('members.relation.label_en'))->required()->maxLength(50),
            Toggle::make('active')->label(__('members.relation.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('label_bn')->label(__('members.relation.label_bn')),
                TextColumn::make('label_en')->label(__('members.relation.label_en')),
                TextColumn::make('key')->label(__('members.relation.key'))->color('gray'),
                IconColumn::make('active')->label(__('members.relation.active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('warning')
                    ->tooltip(__('members.relation.edit'))
                    ->using(fn (NomineeRelation $record, array $data): NomineeRelation => DomainActionRunner::run(
                        fn (User $actor): NomineeRelation => app(SaveNomineeRelation::class)($actor, $record, NomineeRelationData::fromForm($data)),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNomineeRelations::route('/')];
    }
}
