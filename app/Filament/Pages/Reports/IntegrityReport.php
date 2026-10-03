<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Integrity\Jobs\RunIntegrityChecksJob;
use App\Domain\Integrity\Models\IntegrityFinding;
use App\Domain\Integrity\Models\IntegrityRun;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Navigation\NavGroup;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The outcome of the latest integrity run (W7) and its findings; earlier runs via the filter.
 */
final class IntegrityReport extends Page implements HasTable
{
    use ConfirmsWithTier;
    use InteractsWithTable;

    protected string $view = 'filament.pages.reports.integrity';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Reports;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'reports/integrity';

    public static function getNavigationLabel(): string
    {
        return __('integrity.title');
    }

    public function getTitle(): string
    {
        return __('integrity.title');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewReports');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(IntegrityFinding::query())
            ->defaultSort('id')
            ->columns([
                TextColumn::make('check')
                    ->label(__('integrity.columns.check'))
                    ->formatStateUsing(fn (string $state): string => __('integrity.checks.'.$state))
                    ->badge()
                    ->color('danger'),
                TextColumn::make('message')
                    ->label(__('integrity.columns.message'))
                    ->wrap()
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('run')
                    ->label(__('integrity.columns.run'))
                    ->options(fn (): array => IntegrityRun::query()
                        ->orderByDesc('id')
                        ->limit(60)
                        ->get()
                        ->mapWithKeys(fn (IntegrityRun $run): array => [
                            $run->id => Display::dateTime($run->started_at).' · '.$run->status->getLabel(),
                        ])
                        ->all())
                    ->default(fn (): ?int => IntegrityRun::latestFinished()?->id)
                    ->query(fn (Builder $query, array $data): Builder => $query->where('integrity_run_id', $data['value'] ?? 0))
                    ->selectablePlaceholder(false),
            ])
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(__('integrity.no_findings'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['run' => IntegrityRun::latestFinished()];
    }

    protected function getHeaderActions(): array
    {
        return [
            self::tier1(
                Action::make('run')
                    ->label(__('integrity.run_now'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('primary')
                    ->tooltip(__('integrity.run_now_tooltip'))
                    ->visible(fn (): bool => Gate::allows('runIntegrityChecks'))
                    ->action(function (): void {
                        DomainActionRunner::run(function ($actor): void {
                            Gate::forUser($actor)->authorize('runIntegrityChecks');
                            RunIntegrityChecksJob::dispatch($actor->id);
                        });

                        Notification::make()->info()->title(__('integrity.queued'))->send();
                    }),
                heading: __('integrity.run_now'),
                description: __('integrity.run_now_description'),
            ),
        ];
    }
}
