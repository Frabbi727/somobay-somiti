<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Filament\Navigation\NavGroup;
use App\Reports\LedgerDocument;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * @property-read Schema $form
 */
final class AccountLedgerReport extends Page
{
    protected string $view = 'filament.pages.reports.account-ledger';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Reports;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'reports/ledger';

    /** @var array<string, mixed> */
    public array $filters = [];

    public static function getNavigationLabel(): string
    {
        return __('reports.ledger.title');
    }

    public function getTitle(): string
    {
        return __('reports.ledger.title');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewReports');
    }

    public function mount(): void
    {
        $today = CarbonImmutable::now(YearMonth::TIMEZONE);

        $this->form->fill([
            'account_id' => request()->integer('account') ?: null,
            'from' => FiscalCalendar::startsOn(FiscalCalendar::startYearFor($today))->toDateString(),
            'until' => $today->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->columns(4)
            ->components([
                Select::make('account_id')
                    ->label(__('reports.ledger.account'))
                    ->options(fn (): array => Account::withTrashed()->orderBy('code')->get()
                        ->mapWithKeys(fn (Account $account): array => [$account->id => $account->displayName()])
                        ->all())
                    ->searchable()
                    ->live(),
                TextInput::make('member_id')
                    ->label(__('reports.ledger.member'))
                    ->helperText(__('reports.ledger.member_help'))
                    ->integer()
                    ->minValue(1)
                    ->live(onBlur: true),
                DatePicker::make('from')
                    ->label(__('reports.ledger.from'))
                    ->native(false)
                    ->required()
                    ->live(),
                DatePicker::make('until')
                    ->label(__('reports.ledger.until'))
                    ->native(false)
                    ->required()
                    ->live(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $account = $this->account();

        return $account === null
            ? ['report' => null, 'heading' => null]
            : app(LedgerDocument::class)->data($account, $this->date('from'), $this->date('until'), $this->memberId());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label(__('reports.download_pdf'))
                ->tooltip(__('reports.download_pdf'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('info')
                ->disabled(fn (): bool => $this->account() === null)
                ->action(fn (): ?StreamedResponse => $this->download('pdf')),
            Action::make('excel')
                ->label(__('reports.download_excel'))
                ->tooltip(__('reports.download_excel'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->disabled(fn (): bool => $this->account() === null)
                ->action(fn (): ?StreamedResponse => $this->download('xlsx')),
        ];
    }

    private function download(string $extension): ?StreamedResponse
    {
        $account = $this->account();

        if ($account === null) {
            return null;
        }

        $document = app(LedgerDocument::class);
        [$from, $until, $member] = [$this->date('from'), $this->date('until'), $this->memberId()];

        $content = $extension === 'pdf'
            ? $document->pdf($account, $from, $until, $member)
            : $document->excel($account, $from, $until, $member);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $document->filename($account, $from, $until, $extension), [
            'Content-Type' => $extension === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function account(): ?Account
    {
        $id = $this->filters['account_id'] ?? null;

        return is_numeric($id) ? Account::withTrashed()->find((int) $id) : null;
    }

    private function memberId(): ?int
    {
        $member = $this->filters['member_id'] ?? null;

        return is_numeric($member) && (int) $member > 0 ? (int) $member : null;
    }

    private function date(string $key): CarbonImmutable
    {
        $value = $this->filters[$key] ?? null;

        return is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value, YearMonth::TIMEZONE)
            : CarbonImmutable::now(YearMonth::TIMEZONE)->startOfDay();
    }
}
