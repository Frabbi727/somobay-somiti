<?php

declare(strict_types=1);

namespace App\Filament\Pages\Dues;

use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Jobs\GenerateMonthlyDuesJob;
use App\Domain\Contributions\Reports\DueGenerationPlan;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Navigation\NavGroup;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Support\Time\YearMonth;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * "Generate Dues" (T3): preview a month, then type it to queue the run.
 *
 * @property-read Schema $form
 */
final class GenerateDues extends Page
{
    use ConfirmsWithTier;

    protected string $view = 'filament.pages.dues.generate';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Dues;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'dues/generate';

    /** @var array<string, mixed> */
    public array $filters = [];

    public static function getNavigationLabel(): string
    {
        return __('dues.generate.title');
    }

    public function getTitle(): string
    {
        return __('dues.generate.title');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('generateDues');
    }

    public function mount(): void
    {
        $this->form->fill(['month' => (string) YearMonth::current()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                TextInput::make('month')
                    ->label(__('dues.generate.month'))
                    ->helperText(__('dues.generate.month_help'))
                    ->type('month')
                    ->regex('/^\d{4}-\d{2}$/')
                    ->required()
                    ->live(onBlur: true),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $month = $this->month();

        if ($month === null) {
            return ['preview' => null, 'error' => null];
        }

        try {
            return ['preview' => app(GenerateMonthlyDues::class)->preview($month), 'error' => null];
        } catch (DomainRuleViolation $violation) {
            return ['preview' => null, 'error' => $violation->getMessage()];
        }
    }

    protected function getHeaderActions(): array
    {
        $action = Action::make('run')
            ->label(__('dues.generate.run'))
            ->tooltip(__('dues.generate.run'))
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->disabled(fn (): bool => $this->preview()?->newCount() === 0 || $this->preview() === null)
            ->action(function (): void {
                $month = $this->month();

                if ($month === null) {
                    return;
                }

                DomainActionRunner::run(function ($actor) use ($month): void {
                    app(GenerateMonthlyDues::class)->assertMonthAllowed($month);
                    GenerateMonthlyDuesJob::dispatch((string) $month, $actor->id);
                });

                Notification::make()
                    ->title(__('dues.generate.queued', ['month' => Display::yearMonth($month)]))
                    ->info()
                    ->send();
            });

        return [
            self::tier3(
                $action,
                heading: fn (): string => __('dues.generate.run_heading', ['month' => $this->monthLabel()]),
                expected: fn (): string => (string) $this->month(),
                submitLabel: fn (): string => __('dues.generate.run_submit', ['month' => $this->monthLabel()]),
                description: fn (): string => __('dues.generate.run_description', [
                    'count' => Display::digits($this->preview()?->newCount() ?? 0),
                    'amount' => Display::money($this->preview()?->newTotal()),
                ]),
            ),
        ];
    }

    private function preview(): ?DueGenerationPlan
    {
        $data = $this->getViewData();

        return $data['preview'] instanceof DueGenerationPlan ? $data['preview'] : null;
    }

    private function month(): ?YearMonth
    {
        $value = $this->filters['month'] ?? null;

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? YearMonth::parse($value) : null;
    }

    private function monthLabel(): string
    {
        return Display::yearMonth($this->month());
    }
}
