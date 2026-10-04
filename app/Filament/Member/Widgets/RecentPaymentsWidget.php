<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Domain\Contributions\Models\Payment;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Member\Pages\Payments;
use App\Filament\Support\Display;
use Filament\Actions\Action;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

final class RecentPaymentsWidget extends TableWidget
{
    use ScopedToMember;

    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('portal.dashboard.recent'))
            ->query(fn (): Builder => Payment::query()->where('member_id', self::member()->id)->latest('received_on')->latest('id')->limit(5))
            ->paginated(false)
            ->columns([
                TextColumn::make('received_on')->label(__('payments.field.received_on'))->formatStateUsing(fn (Payment $record): string => Display::date($record->received_on)),
                TextColumn::make('method')->label(__('payments.field.method'))->badge()->color('gray'),
                TextColumn::make('amount_poisha')->label(__('payments.field.amount'))->formatStateUsing(fn (Payment $record): string => Display::money($record->amount_poisha))->alignment(Alignment::End),
                TextColumn::make('status')->label(__('payments.field.status'))->badge(),
            ])
            ->headerActions([
                Action::make('all')
                    ->label(__('portal.dashboard.all_payments'))
                    ->tooltip(__('portal.dashboard.all_payments'))
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('gray')
                    ->url(Payments::getUrl()),
            ])
            ->emptyStateHeading(__('portal.dashboard.none'))
            ->emptyStateIcon(Heroicon::OutlinedBanknotes);
    }
}
