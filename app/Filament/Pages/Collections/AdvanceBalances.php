<?php

declare(strict_types=1);

namespace App\Filament\Pages\Collections;

use App\Domain\Contributions\Actions\ApplyAdvance;
use App\Domain\Contributions\Actions\RefundAdvance;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Models\AdvanceLedgerEntry;
use App\Domain\Contributions\Services\PaidThroughCalculator;
use App\Domain\Members\Models\Member;
use App\Enums\Area;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Navigation\NavGroup;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use App\Support\Money\Money;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
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
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Members holding money paid ahead (2111), with paid-through and a refund action (BR-16).
 */
final class AdvanceBalances extends Page implements HasActions, HasSchemas, HasTable
{
    use ConfirmsWithTier;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    protected string $view = 'filament.pages.collections.advance-balances';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Collections;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'collections/advances';

    public static function getNavigationLabel(): string
    {
        return __('payments.advances.title');
    }

    public function getTitle(): string
    {
        return __('payments.advances.title');
    }

    public static function canAccess(): bool
    {
        return Area::Collections->allows(auth()->user() instanceof User ? auth()->user() : null);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Member::query()
                ->addSelect(['advance_poisha' => AdvanceLedgerEntry::query()
                    ->select('balance_after_poisha')
                    ->whereColumn('member_id', 'members.id')
                    ->orderByDesc('id')
                    ->limit(1)])
                ->whereRaw('(SELECT balance_after_poisha FROM advance_ledger_entries e WHERE e.member_id = members.id ORDER BY e.id DESC LIMIT 1) > 0'))
            ->defaultSort('member_no')
            ->columns([
                TextColumn::make('member_no')
                    ->label(__('payments.field.member'))
                    ->formatStateUsing(fn (Member $record): string => $record->displayName())
                    ->searchable(['member_no', 'name_bn', 'name_en']),
                TextColumn::make('advance_poisha')
                    ->label(__('payments.field.advance_balance'))
                    ->formatStateUsing(fn (mixed $state): string => Display::money(Money::ofPoisha((int) $state)))
                    ->alignment(Alignment::End)
                    ->weight('bold'),
                TextColumn::make('paid_through')
                    ->label(__('payments.field.paid_through'))
                    ->state(function (Member $record): string {
                        $month = app(PaidThroughCalculator::class)->for($record);

                        return $month === null ? __('payments.field.not_paid_yet') : Display::yearMonth($month);
                    })
                    ->description(fn (Member $record): string => __('payments.field.estimate', [
                        'count' => Display::digits(app(PaidThroughCalculator::class)->estimatedMonths($record)),
                    ])),
            ])
            ->recordActions([$this->refundAction()])
            ->emptyStateHeading(__('payments.advances.empty'))
            ->emptyStateIcon(Heroicon::OutlinedWallet);
    }

    protected function getHeaderActions(): array
    {
        $action = Action::make('applyAdvance')
            ->label(__('payments.actions.apply_advance'))
            ->tooltip(__('payments.actions.apply_advance_heading'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->visible(fn (): bool => Gate::allows('generateDues'))
            ->action(function (): void {
                ['members' => $members, 'total' => $total] = DomainActionRunner::run(fn (User $actor): array => app(ApplyAdvance::class)(null, $actor));

                Notification::make()
                    ->title(__('payments.actions.advance_applied', ['amount' => Display::money($total), 'count' => Display::digits($members)]))
                    ->success()
                    ->send();
            });

        return [self::tier3(
            $action,
            heading: __('payments.actions.apply_advance_heading'),
            expected: fn (): string => (string) __('confirm.word'),
            submitLabel: __('payments.actions.apply_advance'),
        )];
    }

    private function refundAction(): Action
    {
        $action = Action::make('refund')
            ->label(__('payments.actions.refund'))
            ->tooltip(__('payments.actions.refund'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->iconButton()
            ->visible(fn (): bool => Gate::allows('refundAdvance'))
            ->action(function (Member $record, array $data): void {
                $amount = $data['amount'] instanceof Money ? $data['amount'] : Money::ofTaka((string) $data['amount']);

                DomainActionRunner::run(fn (User $actor) => app(RefundAdvance::class)(
                    $actor,
                    $record,
                    $amount,
                    PaymentMethod::from((string) ($data['paid_from'] instanceof PaymentMethod ? $data['paid_from']->value : $data['paid_from'])),
                    (string) ($data['reason'] ?? ''),
                ));

                Notification::make()
                    ->title(__('payments.actions.refunded', ['amount' => Display::money($amount), 'member' => $record->displayName()]))
                    ->success()
                    ->send();
            });

        return self::tier3(
            $action,
            heading: fn (Member $record): string => __('payments.actions.refund_heading', ['member' => $record->displayName()]),
            expected: fn (Member $record): string => $record->member_no,
            submitLabel: __('payments.actions.refund_submit'),
            fields: [
                MoneyInput::make('amount')->label(__('payments.actions.refund_amount'))->required(),
                Select::make('paid_from')
                    ->label(__('payments.actions.refund_from'))
                    ->options([
                        PaymentMethod::Cash->value => PaymentMethod::Cash->getLabel(),
                        PaymentMethod::Bank->value => PaymentMethod::Bank->getLabel(),
                    ])
                    ->default(PaymentMethod::Cash->value)
                    ->required(),
                Textarea::make('reason')->label(__('payments.field.reason'))->required()->minLength(5)->rows(2),
            ],
        );
    }
}
