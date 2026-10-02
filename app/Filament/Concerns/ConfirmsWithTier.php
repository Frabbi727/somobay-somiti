<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Support\ChangeSummary;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Illuminate\Contracts\View\View;

/**
 * Tiered confirmations (SOMITI_SPEC.md §7.1):
 *  - T1: a specific yes/no question for low-risk, non-financial changes;
 *  - T2: an old → new summary for settings, member and share changes;
 *  - T3: the user types an exact text (code, voucher no, month) for financial or destructive actions.
 *
 * Closures passed here are evaluated by the action, so they can inject $record, $data, etc.
 */
trait ConfirmsWithTier
{
    protected static function tier1(Action $action, string|Closure $heading, string|Closure|null $description = null): Action
    {
        return $action
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription($description);
    }

    /**
     * @param  Closure  $rows  returns the rows built by ChangeSummary::rows(); may inject $record, $data, …
     */
    protected static function tier2(Action $action, string|Closure $heading, Closure $rows, string|Closure|null $description = null, bool $showOld = true): Action
    {
        return $action
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription($description)
            ->modalContent(function (Action $action) use ($rows, $showOld): View {
                $evaluated = $action->evaluate($rows);

                return ChangeSummary::view(is_array($evaluated) ? array_values($evaluated) : [], $showOld);
            });
    }

    protected static function tier3(Action $action, string|Closure $heading, string|Closure $expected, string|Closure $submitLabel, string|Closure|null $description = null): Action
    {
        $expectedText = fn (): string => (string) $action->evaluate($expected);

        return $action
            ->requiresConfirmation()
            ->modalHeading($heading)
            ->modalDescription($description)
            ->modalSubmitActionLabel($submitLabel)
            ->schema([
                TextInput::make('confirm_text')
                    ->label(fn (): string => __('confirm.type_to_confirm', ['text' => $expectedText()]))
                    ->placeholder($expectedText)
                    ->required()
                    ->autocomplete(false)
                    ->in(fn (): array => [$expectedText()])
                    ->validationMessages(['in' => __('confirm.mismatch')]),
            ]);
    }
}
