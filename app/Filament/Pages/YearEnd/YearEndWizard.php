<?php

declare(strict_types=1);

namespace App\Filament\Pages\YearEnd;

use App\Domain\Accounting\Enums\FiscalYearStatus;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Actions\PrepareYearEnd;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Domain\YearEnd\Data\YearEndFigures;
use App\Domain\YearEnd\Models\YearEnd;
use App\Domain\YearEnd\Services\YearEndCalculator;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\YearEnds\YearEndResource;
use App\Filament\Support\ChangeSummary;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * W8 wizard: choose the year and the appropriation rates, see what is still blocking the close and
 * a live preview, then prepare the draft for the president's and accountant's approval.
 *
 * @property-read Schema $form
 */
final class YearEndWizard extends Page
{
    use ConfirmsWithTier;

    protected string $view = 'filament.pages.year-end.wizard';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::YearEnd;

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $slug = 'year-end/closing';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('year_end.wizard.title');
    }

    public function getTitle(): string
    {
        return __('year_end.wizard.title');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('create', YearEnd::class);
    }

    public function mount(): void
    {
        $defaults = AppropriationRates::defaults();

        $this->form->fill([
            'fiscal_year_id' => FiscalYear::query()->where('status', FiscalYearStatus::Open)->orderBy('start_year')->value('id'),
            'reserve' => $defaults->reserve->toPercentString(),
            'development_fund' => $defaults->developmentFund->toPercentString(),
            'bad_debt_fund' => $defaults->badDebtFund->toPercentString(),
            'other_funds' => $defaults->otherFunds->toPercentString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $rate = fn (string $key): TextInput => TextInput::make($key)
            ->label(__('year_end.fund.'.$key))
            ->suffix('%')
            ->regex('/^\d{1,3}(\.\d{1,2})?$/')
            ->required()
            ->live(onBlur: true);

        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        Select::make('fiscal_year_id')
                            ->label(__('year_end.wizard.fiscal_year'))
                            ->options(fn (): array => FiscalYear::query()->where('status', FiscalYearStatus::Open)->orderBy('start_year')->pluck('code', 'id')->all())
                            ->required()
                            ->native(false)
                            ->live(),
                        Select::make('resolution_id')
                            ->label(__('year_end.wizard.resolution'))
                            ->options(fn (): array => Resolution::query()
                                ->where('subject', ResolutionSubject::YearEnd)
                                ->where('status', ResolutionStatus::Passed)
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (Resolution $resolution): array => [$resolution->id => $resolution->displayName()])
                                ->all())
                            ->required(fn (): bool => ResolutionSubject::YearEnd->isRequired())
                            ->native(false),
                        $rate('reserve'),
                        $rate('development_fund'),
                        $rate('bad_debt_fund'),
                        $rate('other_funds'),
                    ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $year = $this->fiscalYear();

        if ($year === null) {
            return ['blocker' => null, 'figures' => null, 'error' => null];
        }

        $blocker = null;

        try {
            app(PrepareYearEnd::class)->assertReady($year);
        } catch (DomainRuleViolation $violation) {
            $blocker = $violation->getMessage();
        }

        try {
            return ['blocker' => $blocker, 'figures' => $this->figures($year), 'error' => null];
        } catch (DomainRuleViolation $violation) {
            return ['blocker' => $blocker, 'figures' => null, 'error' => $violation->getMessage()];
        }
    }

    protected function getHeaderActions(): array
    {
        $action = Action::make('prepare')
            ->label(__('year_end.wizard.prepare'))
            ->tooltip(__('year_end.wizard.prepare'))
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('primary')
            ->mountUsing(fn () => $this->form->validate())
            ->action(function (): void {
                $year = $this->fiscalYear();

                if ($year === null) {
                    return;
                }

                $yearEnd = DomainActionRunner::run(fn (User $actor): YearEnd => app(PrepareYearEnd::class)(
                    $actor,
                    $year,
                    AppropriationRates::fromForm($this->data),
                    is_numeric($this->data['resolution_id'] ?? null) ? (int) $this->data['resolution_id'] : null,
                ));

                Notification::make()->title(__('year_end.wizard.prepared', ['code' => $year->code]))->success()->send();
                $this->redirect(YearEndResource::getUrl('view', ['record' => $yearEnd]));
            });

        return [self::tier2(
            $action,
            heading: fn (): string => __('year_end.wizard.prepare_heading', ['code' => (string) $this->fiscalYear()?->code]),
            rows: function (): array {
                $year = $this->fiscalYear();
                $figures = $year === null ? null : $this->figures($year);

                return $figures === null ? [] : ChangeSummary::rows(
                    self::summaryLabels(),
                    [],
                    self::summaryValues($figures),
                );
            },
            description: __('year_end.wizard.prepare_description'),
            showOld: false,
        )];
    }

    /**
     * @return array<string, string>
     */
    public static function summaryLabels(): array
    {
        return [
            'net_profit' => __('year_end.field.net_profit'),
            'loss_offset' => __('year_end.field.loss_offset'),
            'reserve' => __('year_end.fund.reserve'),
            'development_fund' => __('year_end.fund.development_fund'),
            'bad_debt_fund' => __('year_end.fund.bad_debt_fund'),
            'other_funds' => __('year_end.fund.other_funds'),
            'dividend_pool' => __('year_end.field.dividend_pool'),
            'members' => __('year_end.field.members'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function summaryValues(YearEndFigures $figures): array
    {
        return [
            'net_profit' => Display::money($figures->netProfit),
            'loss_offset' => Display::money($figures->lossOffset),
            'reserve' => Display::money($figures->appropriation['reserve']),
            'development_fund' => Display::money($figures->appropriation['development_fund']),
            'bad_debt_fund' => Display::money($figures->appropriation['bad_debt_fund']),
            'other_funds' => Display::money($figures->appropriation['other_funds']),
            'dividend_pool' => Display::money($figures->dividendPool),
            'members' => Display::digits(count($figures->dividends)),
        ];
    }

    private function figures(FiscalYear $year): YearEndFigures
    {
        return app(YearEndCalculator::class)->calculate($year, AppropriationRates::fromForm($this->data));
    }

    private function fiscalYear(): ?FiscalYear
    {
        $id = $this->data['fiscal_year_id'] ?? null;

        return is_numeric($id) ? FiscalYear::query()->find((int) $id) : null;
    }
}
