<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberExits;

use App\Domain\Exits\Enums\ExitReason;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\MemberExits\Pages\CreateMemberExit;
use App\Filament\Resources\MemberExits\Pages\ListMemberExits;
use App\Filament\Resources\MemberExits\Pages\ViewMemberExit;
use App\Filament\Resources\MemberExits\Schemas\MemberExitForm;
use App\Filament\Resources\MemberExits\Schemas\MemberExitInfolist;
use App\Filament\Support\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class MemberExitResource extends Resource
{
    protected static ?string $model = MemberExit::class;

    protected static ?string $slug = 'exits';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Exits;

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    public static function getModelLabel(): string
    {
        return __('exits.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('exits.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = MemberExit::query()->whereIn('status', [ExitStatus::Requested, ExitStatus::Approved])->count();

        return $open === 0 ? null : Display::digits($open);
    }

    public static function form(Schema $schema): Schema
    {
        return MemberExitForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MemberExitInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('exit_no')->label(__('exits.field.number'))->searchable(),
                TextColumn::make('member.member_no')
                    ->label(__('exits.field.member'))
                    ->formatStateUsing(fn (MemberExit $record): string => $record->member->displayName())
                    ->searchable(['member_no', 'name_en', 'name_bn']),
                TextColumn::make('reason_type')->label(__('exits.field.reason_type'))->badge()->color('gray')->visibleFrom('md'),
                TextColumn::make('exit_month')->label(__('exits.field.exit_month'))->formatStateUsing(fn (MemberExit $record): string => Display::yearMonth($record->exit_month)),
                TextColumn::make('net_poisha')
                    ->label(__('exits.field.net'))
                    ->state(fn (MemberExit $record): ?string => $record->net_poisha === null ? null : Display::money($record->net_poisha))
                    ->placeholder('—')
                    ->alignment(Alignment::End),
                TextColumn::make('status')->label(__('exits.field.status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('exits.field.status'))->options(ExitStatus::class),
                SelectFilter::make('reason_type')->label(__('exits.field.reason_type'))->options(ExitReason::class),
            ])
            ->recordActions([ViewAction::make()->iconButton()])
            ->emptyStateHeading(__('exits.plural'))
            ->emptyStateIcon(Heroicon::OutlinedArrowRightStartOnRectangle);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberExits::route('/'),
            'create' => CreateMemberExit::route('/create'),
            'view' => ViewMemberExit::route('/{record}'),
        ];
    }
}
