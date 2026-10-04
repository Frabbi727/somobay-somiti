<?php

declare(strict_types=1);

namespace App\Filament\Resources\Meetings\RelationManagers;

use App\Domain\Governance\Models\Meeting;
use App\Domain\Governance\Models\Resolution;
use App\Filament\Resources\Meetings\Actions\MeetingActions;
use App\Filament\Support\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class ResolutionsRelationManager extends RelationManager
{
    protected static string $relationship = 'resolutions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('governance.resolution.plural');
    }

    /**
     * Proposing and voting are guarded by policies, so they stay available on the view page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $meeting = $this->getOwnerRecord();

        return $table
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('resolution_no')->label(__('governance.field.number'))->weight('bold'),
                TextColumn::make('title')->label(__('governance.field.title'))->description(fn (Resolution $record): string => $record->subject->getLabel())->wrap(),
                TextColumn::make('majority')->label(__('governance.field.majority'))->badge()->color('gray')->visibleFrom('md'),
                TextColumn::make('status')->label(__('governance.field.status'))->badge(),
                TextColumn::make('votes')
                    ->label(__('governance.field.votes'))
                    ->state(fn (Resolution $record): ?string => $record->votes_for === null ? null : (string) __('governance.field.votes_value', [
                        'for' => Display::digits($record->votes_for),
                        'against' => Display::digits((int) $record->votes_against),
                        'abstain' => Display::digits((int) $record->votes_abstain),
                    ]))
                    ->placeholder('—'),
            ])
            ->headerActions($meeting instanceof Meeting ? [MeetingActions::propose()->record($meeting)] : [])
            ->recordActions([
                MeetingActions::decide()->iconButton(),
                MeetingActions::withdraw()->iconButton(),
            ]);
    }
}
