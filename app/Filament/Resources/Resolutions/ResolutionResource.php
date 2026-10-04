<?php

declare(strict_types=1);

namespace App\Filament\Resources\Resolutions;

use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Models\Resolution;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\Meetings\MeetingResource;
use App\Filament\Resources\Resolutions\Pages\ListResolutions;
use App\Filament\Support\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The resolution register: every resolution, across meetings. Read-only; voting happens on the meeting.
 */
final class ResolutionResource extends Resource
{
    protected static ?string $model = Resolution::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Governance;

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    public static function getModelLabel(): string
    {
        return __('governance.resolution.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('governance.resolution.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('meeting'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('resolution_no')->label(__('governance.field.number'))->searchable()->weight('bold'),
                TextColumn::make('title')->label(__('governance.field.title'))->searchable(['title', 'body'])->wrap(),
                TextColumn::make('subject')->label(__('governance.field.subject'))->badge()->color('gray'),
                TextColumn::make('status')->label(__('governance.field.status'))->badge(),
                TextColumn::make('meeting.meeting_no')->label(__('governance.field.meeting'))->visibleFrom('md'),
                TextColumn::make('decided_at')
                    ->label(__('governance.field.held_at'))
                    ->state(fn (Resolution $record): ?string => $record->decided_at === null ? null : Display::date($record->decided_at))
                    ->placeholder('—')
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('subject')->label(__('governance.field.subject'))->options(ResolutionSubject::class),
                SelectFilter::make('status')->label(__('governance.field.status'))->options(ResolutionStatus::class),
            ])
            ->recordActions([
                Action::make('meeting')
                    ->label(__('governance.field.meeting'))
                    ->tooltip(__('governance.field.meeting'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->color('gray')
                    ->iconButton()
                    ->url(fn (Resolution $record): string => MeetingResource::getUrl('view', ['record' => $record->meeting_id])),
            ])
            ->emptyStateHeading(__('governance.resolution.plural'))
            ->emptyStateIcon(Heroicon::OutlinedDocumentCheck);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResolutions::route('/'),
        ];
    }
}
