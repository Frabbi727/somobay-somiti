<?php

declare(strict_types=1);

namespace App\Filament\Resources\StatementImports\Actions;

use App\Domain\Accounting\Actions\IgnoreStatementLine;
use App\Domain\Accounting\Actions\MatchStatementLine;
use App\Domain\Accounting\Actions\UnmatchStatementLine;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\StatementImport;
use App\Domain\Accounting\Models\StatementLine;
use App\Domain\Accounting\Statements\StatementMatcher;
use App\Filament\Concerns\ConfirmsWithTier;
use App\Filament\Support\Display;
use App\Filament\Support\DomainActionRunner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Reconciliation never changes the books, so these are T1 confirmations.
 */
final class StatementLineActions
{
    use ConfirmsWithTier;

    /**
     * Book entries offered for a manual match: same account, amount and direction, within a month.
     */
    public const int MANUAL_WINDOW_DAYS = 31;

    public static function match(): Action
    {
        $action = Action::make('match')
            ->label(__('statements.actions.match'))
            ->tooltip(__('statements.actions.match'))
            ->icon(Heroicon::OutlinedLink)
            ->color('success')
            ->authorize('match')
            ->schema([
                Select::make('journal_line_id')
                    ->label(__('statements.field.candidate'))
                    ->options(fn (StatementLine $record): array => app(StatementMatcher::class)
                        ->candidates($record, self::MANUAL_WINDOW_DAYS)
                        ->mapWithKeys(fn (JournalLine $line): array => [$line->id => sprintf(
                            '%s · %s · %s',
                            Display::date($line->entry->entry_date),
                            Display::digits($line->entry->voucher_no),
                            mb_strimwidth($line->entry->narration, 0, 60, '…'),
                        )])
                        ->all())
                    ->required()
                    ->native(false),
            ])
            ->action(function (StatementLine $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): StatementLine => app(MatchStatementLine::class)(
                    $actor,
                    $record,
                    JournalLine::query()->findOrFail((int) ($data['journal_line_id'] ?? 0)),
                ));
                Notification::make()->title(__('statements.notifications.matched'))->success()->send();
            });

        return self::tier1($action, fn (StatementLine $record): string => __('statements.actions.match_heading', ['amount' => Display::money($record->amount_poisha)]));
    }

    public static function unmatch(): Action
    {
        $action = Action::make('unmatch')
            ->label(__('statements.actions.unmatch'))
            ->tooltip(__('statements.actions.unmatch'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->authorize('unmatch')
            ->action(function (StatementLine $record): void {
                DomainActionRunner::run(fn (User $actor): StatementLine => app(UnmatchStatementLine::class)($actor, $record));
                Notification::make()->title(__('statements.notifications.unmatched'))->success()->send();
            });

        return self::tier1($action, __('statements.actions.unmatch_heading'));
    }

    public static function ignore(): Action
    {
        $action = Action::make('ignore')
            ->label(__('statements.actions.ignore'))
            ->tooltip(__('statements.actions.ignore'))
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('gray')
            ->authorize('ignore')
            ->schema([Textarea::make('reason')->label(__('statements.field.reason'))->required()->minLength(5)->rows(2)])
            ->action(function (StatementLine $record, array $data): void {
                DomainActionRunner::run(fn (User $actor): StatementLine => app(IgnoreStatementLine::class)($actor, $record, (string) ($data['reason'] ?? '')));
                Notification::make()->title(__('statements.notifications.ignored'))->success()->send();
            });

        return self::tier1($action, __('statements.actions.ignore_heading'), __('statements.actions.ignore_description'));
    }

    public static function autoMatch(): Action
    {
        $action = Action::make('automatch')
            ->label(__('statements.actions.automatch'))
            ->tooltip(__('statements.actions.automatch'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('primary')
            ->visible(fn (StatementImport $record): bool => Gate::allows('create', StatementImport::class))
            ->action(function (StatementImport $record): void {
                $count = DomainActionRunner::run(fn (User $actor): int => app(StatementMatcher::class)->autoMatch($record, $actor));
                Notification::make()->title(__('statements.notifications.automatched', ['count' => Display::digits($count)]))->success()->send();
            });

        return self::tier1($action, __('statements.actions.automatch_heading'));
    }
}
