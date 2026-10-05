<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\Users;

use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\Users\Actions\UserActions;
use App\Filament\Clusters\Settings\Resources\Users\Pages\CreateUser;
use App\Filament\Clusters\Settings\Resources\Users\Pages\EditUser;
use App\Filament\Clusters\Settings\Resources\Users\Pages\ListUsers;
use App\Filament\Clusters\Settings\Resources\Users\Schemas\UserForm;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Staff accounts (super admin only). Members' portal logins are managed from the member record.
 */
final class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.plural');
    }

    /**
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return User::query()
            ->with('roles')
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('name', Role::staff()));
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('users.field.name'))->searchable()->sortable()->weight('bold'),
                TextColumn::make('email')->label(__('users.field.email'))->searchable()->sortable(),
                TextColumn::make('mobile')->label(__('users.field.mobile'))->searchable()->visibleFrom('md'),
                TextColumn::make('roles.name')
                    ->label(__('users.field.roles'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Role::from($state)->getLabel()),
                TextColumn::make('deactivated_at')
                    ->label(__('users.field.status'))
                    ->state(fn (User $record): string => __($record->isActive() ? 'users.status.active' : 'users.status.inactive'))
                    ->badge()
                    ->color(fn (User $record): string => $record->isActive() ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label(__('users.field.status'))
                    ->trueLabel(__('users.status.active'))
                    ->falseLabel(__('users.status.inactive'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('deactivated_at'),
                        false: fn (Builder $query) => $query->whereNotNull('deactivated_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                UserActions::deactivate()->iconButton(),
                UserActions::reactivate()->iconButton(),
            ])
            ->emptyStateHeading(__('users.plural'))
            ->emptyStateIcon(Heroicon::OutlinedUsers);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
