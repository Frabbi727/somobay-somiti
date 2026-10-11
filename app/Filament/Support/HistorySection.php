<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Reporting\RecordTimeline;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

/**
 * The "History" box on a record's page: what happened, when, by whom, and who is needed next.
 */
final class HistorySection
{
    public static function make(): Section
    {
        return Section::make(__('timeline.heading'))
            ->icon('heroicon-o-clock')
            ->columnSpanFull()
            ->collapsible()
            ->schema([
                ViewEntry::make('history')
                    ->hiddenLabel()
                    ->view('filament.registration.timeline')
                    ->state(fn (Model $record): array => app(RecordTimeline::class)->steps($record)),
            ]);
    }
}
