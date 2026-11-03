<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Domain\Settings\Actions\ApproveFinancialSettings;
use App\Domain\Settings\Actions\CancelFinancialSettings;
use App\Domain\Settings\Actions\ProposeFinancialSettings;
use App\Domain\Settings\Actions\RejectFinancialSettings;
use App\Domain\Settings\Data\FinancialSettingsData;
use App\Domain\Settings\Enums\FinancialSettingsStatus;
use App\Domain\Settings\Models\FinancialSettingsVersion;
use App\Domain\Settings\Services\FinancialSettings;
use App\Domain\YearEnd\Enums\StatutoryBase;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * T-19: the financial settings in force, a form to propose new ones, and every proposal with its
 * decision. A proposal applies only after someone other than the proposer approves it.
 *
 * @property-read Schema $form
 */
final class FinancialSettingsPage extends Page implements HasActions, HasSchemas, HasTable
{
    use ConfirmsWithTier;
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    private const string PERCENT_RULE = '/^\d{1,3}(\.\d{1,2})?$/';

    protected string $view = 'filament.clusters.settings.financial-settings';

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'financial-settings';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('financial_settings.title');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = FinancialSettingsVersion::query()->where('status', FinancialSettingsStatus::Pending)->count();

        return $pending > 0 ? Display::digits((string) $pending) : null;
    }

    public function getTitle(): string
    {
        return __('financial_settings.title');
    }

    public function getSubheading(): string
    {
        return __('financial_settings.subheading');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', FinancialSettingsVersion::class);
    }

    public function mount(): void
    {
        $this->form->fill([...app(FinancialSettings::class)->current()->toForm(), 'reason' => null, 'idempotency_key' => (string) Str::uuid()]);
    }

    public function form(Schema $schema): Schema
    {
        $subjects = [];

        foreach (FinancialSettingsData::RESOLUTION_SUBJECTS as $subject) {
            $subjects[$subject->value] = $subject->getLabel();
        }

        $percent = fn (string $key): TextInput => TextInput::make($key)
            ->label(__('financial_settings.field.'.$key))
            ->suffix('%')
            ->regex(self::PERCENT_RULE)
            ->required()
            ->live(onBlur: true);

        return $schema->statePath('data')->columns(2)->components([
            Section::make(__('financial_settings.section.approvals'))->columns(3)->columnSpanFull()->schema([
                MoneyInput::make('expense_president_threshold')->label(__('financial_settings.field.expense_president_threshold'))->required()->live(onBlur: true),
                MoneyInput::make('advance_refund_president_threshold')->label(__('financial_settings.field.advance_refund_president_threshold'))->required()->live(onBlur: true),
                TextInput::make('approval_reminder_days')
                    ->label(__('financial_settings.field.approval_reminder_days'))
                    ->helperText(__('financial_settings.field.approval_reminder_days_help'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(30)
                    ->required()
                    ->live(onBlur: true),
            ]),
            Section::make(__('financial_settings.section.governance'))->columns(3)->columnSpanFull()->schema([
                $percent('quorum_committee'),
                $percent('quorum_general'),
                $percent('quorum_special_general'),
                CheckboxList::make('resolution_required_for')
                    ->label(__('financial_settings.field.resolution_required_for'))
                    ->options($subjects)
                    ->columns(4)
                    ->columnSpanFull()
                    ->live(),
            ]),
            Section::make(__('financial_settings.section.year_end'))
                ->description(__('financial_settings.section.year_end_help'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Toggle::make('financing_society')->label(__('financial_settings.field.financing_society'))->live(),
                    Select::make('statutory_base')->label(__('financial_settings.field.statutory_base'))->options(StatutoryBase::class)->required()->native(false)->live(),
                    $percent('reserve'),
                    $percent('development_fund'),
                    $percent('bad_debt_fund'),
                    $percent('other_funds'),
                ]),
            Textarea::make('reason')->label(__('financial_settings.field.reason'))->required()->minLength(ProposeFinancialSettings::MIN_REASON_LENGTH)->rows(2)->columnSpanFull(),
        ])->disabled(fn (): bool => ! $this->canPropose());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('financial_settings.history'))
            ->query(FinancialSettingsVersion::query()->with(['proposer', 'decider']))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('financial_settings.history_empty'))
            ->columns([
                TextColumn::make('id')->label('#')->formatStateUsing(fn (int $state): string => Display::digits((string) $state)),
                TextColumn::make('status')->label(__('financial_settings.field.status'))->badge(),
                TextColumn::make('changes')
                    ->label(__('financial_settings.field.changes'))
                    ->state(fn (FinancialSettingsVersion $record): string => $this->describeChanges($record))
                    ->html()
                    ->wrap(),
                TextColumn::make('reason')
                    ->label(__('financial_settings.field.reason'))
                    ->state(fn (FinancialSettingsVersion $record): string => $record->rejection_reason === null ? $record->reason : $record->reason.' · '.__('financial_settings.rejected_because', ['reason' => $record->rejection_reason]))
                    ->wrap(),
                TextColumn::make('proposer.name')->label(__('financial_settings.field.proposed_by'))
                    ->description(fn (FinancialSettingsVersion $record): string => Display::dateTime($record->created_at)),
                TextColumn::make('decider.name')->label(__('financial_settings.field.decided_by'))->placeholder('—')
                    ->description(fn (FinancialSettingsVersion $record): ?string => $record->decided_at === null ? null : Display::dateTime($record->decided_at)),
            ])
            ->recordActions([$this->approveAction(), $this->rejectAction(), $this->cancelAction()]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->proposeAction()];
    }

    public function proposeAction(): Action
    {
        return self::tier2(
            Action::make('proposeSettings')
                ->label(__('financial_settings.actions.propose'))
                ->tooltip(__('financial_settings.actions.propose'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('primary')
                ->visible(fn (): bool => $this->canPropose())
                ->mountUsing(fn () => $this->form->validate())
                ->action(function (): void {
                    $state = $this->form->getState();
                    DomainActionRunner::run(fn (User $actor): FinancialSettingsVersion => app(ProposeFinancialSettings::class)(
                        $actor,
                        FinancialSettingsData::fromForm($state),
                        (string) ($state['reason'] ?? ''),
                        (string) ($this->data['idempotency_key'] ?? Str::uuid()),
                    ));
                    Notification::make()->title(__('financial_settings.notifications.proposed'))->success()->send();
                    $this->mount();
                }),
            heading: __('financial_settings.actions.propose_heading'),
            rows: fn (): array => $this->proposedChangeRows(),
            description: __('financial_settings.actions.propose_description'),
        );
    }

    public function approveAction(): Action
    {
        return self::tier3(
            Action::make('approveSettings')
                ->label(__('financial_settings.actions.approve'))
                ->tooltip(__('financial_settings.actions.approve'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->authorize('approve')
                ->action(function (FinancialSettingsVersion $record): void {
                    DomainActionRunner::run(fn (User $actor): FinancialSettingsVersion => app(ApproveFinancialSettings::class)($actor, $record));
                    Notification::make()->title(__('financial_settings.notifications.approved'))->success()->send();
                    $this->mount();
                }),
            heading: fn (FinancialSettingsVersion $record): string => __('financial_settings.actions.approve_heading', ['number' => Display::digits((string) $record->id)]),
            expected: fn (FinancialSettingsVersion $record): string => (string) $record->id,
            submitLabel: __('financial_settings.actions.approve'),
            description: fn (FinancialSettingsVersion $record): string => __('financial_settings.actions.approve_description', [
                'changes' => strip_tags(str_replace('<br>', '; ', $this->describeChanges($record, against: app(FinancialSettings::class)->current()))),
            ]),
        );
    }

    public function rejectAction(): Action
    {
        return self::tier3(
            Action::make('rejectSettings')
                ->label(__('financial_settings.actions.reject'))
                ->tooltip(__('financial_settings.actions.reject'))
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->authorize('reject')
                ->action(function (FinancialSettingsVersion $record, array $data): void {
                    DomainActionRunner::run(fn (User $actor): FinancialSettingsVersion => app(RejectFinancialSettings::class)($actor, $record, (string) ($data['reason'] ?? '')));
                    Notification::make()->title(__('financial_settings.notifications.rejected'))->success()->send();
                }),
            heading: fn (FinancialSettingsVersion $record): string => __('financial_settings.actions.reject_heading', ['number' => Display::digits((string) $record->id)]),
            expected: fn (FinancialSettingsVersion $record): string => (string) $record->id,
            submitLabel: __('financial_settings.actions.reject'),
            fields: [Textarea::make('reason')->label(__('financial_settings.field.rejection_reason'))->required()->minLength(RejectFinancialSettings::MIN_REASON_LENGTH)],
        );
    }

    public function cancelAction(): Action
    {
        return self::tier1(
            Action::make('cancelSettings')
                ->label(__('financial_settings.actions.cancel'))
                ->tooltip(__('financial_settings.actions.cancel'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('gray')
                ->authorize('cancel')
                ->action(function (FinancialSettingsVersion $record): void {
                    DomainActionRunner::run(fn (User $actor): FinancialSettingsVersion => app(CancelFinancialSettings::class)($actor, $record));
                    Notification::make()->title(__('financial_settings.notifications.cancelled'))->success()->send();
                    $this->mount();
                }),
            fn (FinancialSettingsVersion $record): string => __('financial_settings.actions.cancel_heading', ['number' => Display::digits((string) $record->id)]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $settings = app(FinancialSettings::class);
        $version = $settings->currentVersion();

        return [
            'current' => $settings->current()->display(),
            'version' => $version,
            'pending' => FinancialSettingsVersion::query()->where('status', FinancialSettingsStatus::Pending)->exists(),
        ];
    }

    private function canPropose(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('create', FinancialSettingsVersion::class)
            && ! FinancialSettingsVersion::query()->where('status', FinancialSettingsStatus::Pending)->exists();
    }

    /**
     * @return list<array{label: string, old: string, new: string}>
     */
    private function proposedChangeRows(): array
    {
        try {
            $proposed = FinancialSettingsData::fromForm($this->data);
        } catch (\Throwable) {
            return [];
        }

        $rows = [];

        foreach ($proposed->changesFrom(app(FinancialSettings::class)->current()) as $key => [$old, $new]) {
            $rows[] = ['label' => __('financial_settings.field.'.$key), 'old' => $old, 'new' => $new];
        }

        return $rows;
    }

    /**
     * What a version changed: against the settings in force when it was proposed, or $against.
     */
    private function describeChanges(FinancialSettingsVersion $record, ?FinancialSettingsData $against = null): string
    {
        $before = $against ?? $this->inForceBefore($record);
        $lines = [];

        foreach ($record->values()->changesFrom($before) as $key => [$old, $new]) {
            $lines[] = e(__('financial_settings.field.'.$key)).': '.e($old).' → '.e($new);
        }

        return $lines === [] ? e(__('financial_settings.no_changes')) : implode('<br>', $lines);
    }

    private function inForceBefore(FinancialSettingsVersion $record): FinancialSettingsData
    {
        $previous = FinancialSettingsVersion::query()
            ->where('status', FinancialSettingsStatus::Approved)
            ->where('decided_at', '<', $record->created_at)
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->first();

        return $previous === null ? FinancialSettingsData::fromConfig() : $previous->values();
    }
}
