<?php

declare(strict_types=1);

namespace App\Filament\Resources\Meetings;

use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Enums\MeetingType;
use App\Domain\Governance\Models\Meeting;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\Meetings\Pages\CreateMeeting;
use App\Filament\Resources\Meetings\Pages\EditMeeting;
use App\Filament\Resources\Meetings\Pages\ListMeetings;
use App\Filament\Resources\Meetings\Pages\ViewMeeting;
use App\Filament\Resources\Meetings\RelationManagers\ResolutionsRelationManager;
use App\Filament\Resources\Meetings\Schemas\MeetingForm;
use App\Filament\Resources\Meetings\Schemas\MeetingInfolist;
use App\Filament\Support\Display;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class MeetingResource extends Resource
{
    protected static ?string $model = Meeting::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Governance;

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function getModelLabel(): string
    {
        return __('governance.meeting.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('governance.meeting.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return MeetingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MeetingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                TextColumn::make('meeting_no')->label(__('governance.field.number'))->searchable(),
                TextColumn::make('scheduled_at')
                    ->label(__('governance.field.scheduled_at'))
                    ->formatStateUsing(fn (Meeting $record): string => Display::dateTime($record->scheduled_at))
                    ->sortable(),
                TextColumn::make('title')->label(__('governance.field.title'))->searchable()->limit(50),
                TextColumn::make('type')->label(__('governance.field.type'))->badge()->visibleFrom('md'),
                TextColumn::make('status')->label(__('governance.field.status'))->badge(),
                TextColumn::make('quorum_met')
                    ->label(__('governance.field.quorum'))
                    ->state(fn (Meeting $record): ?string => $record->status === MeetingStatus::Held
                        ? Display::digits((int) $record->attendees_count).' / '.Display::digits((int) $record->quorum_required)
                        : null)
                    ->color(fn (Meeting $record): string => $record->quorum_met === true ? 'success' : 'danger')
                    ->placeholder('—')
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('governance.field.type'))->options(MeetingType::class),
                SelectFilter::make('status')->label(__('governance.field.status'))->options(MeetingStatus::class),
            ])
            ->recordActions([
                ViewAction::make()->iconButton(),
                EditAction::make()->iconButton(),
            ])
            ->emptyStateHeading(__('governance.meeting.plural'))
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays);
    }

    public static function getRelations(): array
    {
        return [ResolutionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMeetings::route('/'),
            'create' => CreateMeeting::route('/create'),
            'view' => ViewMeeting::route('/{record}'),
            'edit' => EditMeeting::route('/{record}/edit'),
        ];
    }
}
