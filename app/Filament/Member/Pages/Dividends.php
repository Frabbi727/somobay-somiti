<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\YearEnd\Models\DividendLine;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Support\Display;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The member's dividend for each closed year.
 */
final class Dividends extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopedToMember;

    protected string $view = 'filament.member.table';

    protected static ?int $navigationSort = 60;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    public static function getNavigationLabel(): string
    {
        return __('portal.nav.dividends');
    }

    public function getTitle(): string
    {
        return __('portal.nav.dividends');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => DividendLine::query()->where('member_id', self::member()->id)->with('yearEnd.fiscalYear'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('yearEnd.fiscalYear.code')->label(__('year_end.wizard.fiscal_year'))->weight('bold'),
                TextColumn::make('share_months')->label(__('year_end.field.share_months'))->formatStateUsing(fn (int $state): string => Display::digits($state))->alignment(Alignment::End),
                TextColumn::make('amount_poisha')->label(__('year_end.field.dividend'))->formatStateUsing(fn (DividendLine $record): string => Display::money($record->amount_poisha))->alignment(Alignment::End),
                TextColumn::make('status')->label(__('year_end.field.status'))->badge(),
            ])
            ->emptyStateHeading(__('portal.dividends.none'))
            ->emptyStateIcon(Heroicon::OutlinedGift);
    }
}
