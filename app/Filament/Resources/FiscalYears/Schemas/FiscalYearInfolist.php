<?php

declare(strict_types=1);

namespace App\Filament\Resources\FiscalYears\Schemas;

use App\Domain\Accounting\Models\FiscalYear;
use App\Filament\Support\Display;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class FiscalYearInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label(__('accounting.fiscal_year.code'))
                            ->formatStateUsing(fn (string $state): string => Display::digits($state))
                            ->weight('bold'),
                        TextEntry::make('starts_on')
                            ->label(__('accounting.fiscal_year.starts_on'))
                            ->state(fn (FiscalYear $record): string => Display::date($record->starts_on)),
                        TextEntry::make('ends_on')
                            ->label(__('accounting.fiscal_year.ends_on'))
                            ->state(fn (FiscalYear $record): string => Display::date($record->ends_on)),
                        TextEntry::make('status')
                            ->label(__('accounting.fiscal_year.status'))
                            ->badge(),
                        TextEntry::make('closed_at')
                            ->label(__('accounting.fiscal_year.closed_at'))
                            ->state(fn (FiscalYear $record): string => Display::dateTime($record->closed_at)),
                        TextEntry::make('closedBy.name')
                            ->label(__('accounting.fiscal_year.closed_by'))
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
