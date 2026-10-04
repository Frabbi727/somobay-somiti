<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Filament\Member\Concerns\ScopedToMember;
use App\Filament\Support\Display;
use App\Reports\ReceiptDocument;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every payment the member made or reported, with a receipt once approved.
 */
final class Payments extends Page implements HasTable
{
    use InteractsWithTable;
    use ScopedToMember;

    protected string $view = 'filament.member.table';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    public static function getNavigationLabel(): string
    {
        return __('portal.nav.receipts');
    }

    public function getTitle(): string
    {
        return __('portal.nav.receipts');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Payment::query()->where('member_id', self::member()->id)->with('journalEntry'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('received_on')->orderByDesc('id'))
            ->columns([
                TextColumn::make('received_on')->label(__('payments.field.received_on'))->formatStateUsing(fn (Payment $record): string => Display::date($record->received_on))->sortable(),
                TextColumn::make('method')->label(__('payments.field.method'))->badge()->color('gray'),
                TextColumn::make('trx_id')->label(__('payments.field.trx_id'))->placeholder('—')->visibleFrom('md'),
                TextColumn::make('amount_poisha')->label(__('payments.field.amount'))->formatStateUsing(fn (Payment $record): string => Display::money($record->amount_poisha))->alignment(Alignment::End)->weight('bold'),
                TextColumn::make('status')->label(__('payments.field.status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('payments.field.status'))->options(PaymentStatus::class),
            ])
            ->recordActions([
                Action::make('receipt')
                    ->label(__('portal.receipts.download'))
                    ->tooltip(__('portal.receipts.download'))
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('info')
                    ->iconButton()
                    ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Approved)
                    ->url(fn (Payment $record): string => ReceiptDocument::signedUrl($record), shouldOpenInNewTab: true),
            ])
            ->headerActions([
                Action::make('pay')
                    ->label(__('portal.nav.submit'))
                    ->tooltip(__('portal.nav.submit'))
                    ->icon(Heroicon::OutlinedDevicePhoneMobile)
                    ->color('success')
                    ->url(PayOnline::getUrl()),
            ])
            ->emptyStateHeading(__('portal.receipts.none'))
            ->emptyStateIcon(Heroicon::OutlinedBanknotes);
    }
}
