<?php

declare(strict_types=1);

namespace App\Filament\Pages\Collections;

use App\Domain\Contributions\Actions\ReverseAdvanceRefund;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Enums\Area;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Navigation\NavGroup;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Advance refunds already paid out (BR-16), newest first, with who paid them and an action to
 * reverse one made by mistake.
 */
final class AdvanceRefunds extends Page implements HasActions, HasSchemas, HasTable
{
    use ConfirmsWithTier;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected string $view = 'filament.pages.collections.advance-refunds';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Collections;

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'collections/advance-refunds';

    public static function getNavigationLabel(): string
    {
        return __('payments.refunds.title');
    }

    public function getTitle(): string
    {
        return __('payments.refunds.title');
    }

    public static function canAccess(): bool
    {
        return Area::Collections->allows(auth()->user() instanceof User ? auth()->user() : null);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AdvanceLedgerEntry::query()
                ->where('kind', AdvanceEntryKind::Refund)
                ->with(['member', 'journalEntry', 'creator', 'reversal.journalEntry', 'reversal.creator']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('payments.refunds.date'))
                    ->formatStateUsing(fn (AdvanceLedgerEntry $record): string => Display::date($record->created_at)),
                TextColumn::make('member.member_no')
                    ->label(__('payments.field.member'))
                    ->formatStateUsing(fn (AdvanceLedgerEntry $record): string => $record->member->displayName())
                    ->searchable(['member_no', 'name_bn', 'name_en']),
                TextColumn::make('delta_poisha')
                    ->label(__('payments.refunds.amount'))
                    ->formatStateUsing(fn (AdvanceLedgerEntry $record): string => Display::money($record->delta_poisha->negated()))
                    ->alignment(Alignment::End)
                    ->weight('bold'),
                TextColumn::make('journalEntry.voucher_no')
                    ->label(__('payments.refunds.voucher'))
                    ->formatStateUsing(fn (AdvanceLedgerEntry $record): string => Display::digits((string) $record->journalEntry?->voucher_no))
                    ->description(fn (AdvanceLedgerEntry $record): string => (string) $record->journalEntry?->reason),
                TextColumn::make('creator.name')
                    ->label(__('payments.refunds.refunded_by')),
                TextColumn::make('status')
                    ->label(__('payments.refunds.status'))
                    ->badge()
                    ->state(fn (AdvanceLedgerEntry $record): string => $record->reversal === null
                        ? __('payments.refunds.status_refunded')
                        : __('payments.refunds.status_reversed'))
                    ->color(fn (AdvanceLedgerEntry $record): string => $record->reversal === null ? 'success' : 'gray')
                    ->description(fn (AdvanceLedgerEntry $record): ?string => $record->reversal === null ? null : (string) __('payments.refunds.reversed_detail', [
                        'voucher' => Display::digits((string) $record->reversal->journalEntry?->voucher_no),
                        'by' => (string) $record->reversal->creator?->name,
                        'date' => Display::date($record->reversal->created_at),
                    ])),
            ])
            ->recordActions([$this->reverseAction()])
            ->emptyStateHeading(__('payments.refunds.empty'))
            ->emptyStateIcon(Heroicon::OutlinedArrowUturnLeft);
    }

    private function reverseAction(): Action
    {
        $action = Action::make('reverse')
            ->label(__('payments.refunds.reverse'))
            ->tooltip(__('payments.refunds.reverse'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->iconButton()
            ->visible(fn (AdvanceLedgerEntry $record): bool => $record->reversal === null)
            ->authorize('reverseRefund')
            ->action(function (AdvanceLedgerEntry $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): AdvanceLedgerEntry => app(ReverseAdvanceRefund::class)($actor, $record, (string) ($data['reason'] ?? '')));

                Notification::make()
                    ->title(__('payments.refunds.reversed', [
                        'amount' => Display::money($record->delta_poisha->negated()),
                        'member' => $record->member->displayName(),
                    ]))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (AdvanceLedgerEntry $record): string => __('payments.refunds.reverse_heading', [
                'amount' => Display::money($record->delta_poisha->negated()),
                'member' => $record->member->displayName(),
            ]),
            expected: fn (AdvanceLedgerEntry $record): string => $record->member->member_no,
            submitLabel: __('payments.refunds.reverse'),
            description: __('payments.refunds.reverse_description'),
            fields: [Textarea::make('reason')->label(__('payments.field.reason'))->required()->minLength(5)->rows(2)],
        );
    }
}
