<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Support;

use App\Domain\Contributions\BackDues\BackDuesPlan;
use App\Domain\Contributions\BackDues\BackDuesPlanner;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Filament\Support\Display;
use App\Support\Time\YearMonth;
use Illuminate\Support\HtmlString;

/**
 * The late-joiner breakdown shown on the New Member form and in its confirmation, built from the
 * same BackDuesPlanner that writes the dues, so what staff see is what is billed.
 */
final class BackDuesPreview
{
    /**
     * @param  array<string, mixed>  $state  the form's shares / effective_from / dues_start_month
     */
    public static function plan(array $state): BackDuesPlan|string|null
    {
        $shares = is_numeric($state['shares'] ?? null) ? (int) $state['shares'] : 0;
        $shareStart = self::month($state['effective_from'] ?? null);
        $duesStart = self::month($state['dues_start_month'] ?? null) ?? $shareStart;

        if ($shares < 1 || $shareStart === null || $duesStart === null) {
            return null;
        }

        try {
            return app(BackDuesPlanner::class)->plan($shares, $duesStart, $shareStart);
        } catch (DomainRuleViolation $violation) {
            return $violation->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function html(array $state): HtmlString
    {
        $plan = self::plan($state);

        if ($plan === null) {
            return new HtmlString('');
        }

        if (is_string($plan)) {
            return new HtmlString('<p class="text-sm font-medium text-danger-600 dark:text-danger-400">'.e($plan).'</p>');
        }

        if (! $plan->hasBackMonths()) {
            return new HtmlString('<p class="text-sm text-gray-600 dark:text-gray-400">'.e(__('members.back_dues.none')).'</p>');
        }

        $rows = '';

        foreach ($plan->months as $row) {
            $rows .= self::row(Display::yearMonth($row['month']), __('members.back_dues.month_amounts', [
                'deposit' => Display::moneyShort($row['deposit']),
                'service' => Display::moneyShort($row['serviceCharge']),
            ]));
        }

        $rows .= self::row(__('members.back_dues.registration'), Display::moneyShort($plan->registrationFee));
        $rows .= self::row(__('members.back_dues.premium', ['rate' => $plan->premiumRate->format(app()->getLocale())]), Display::moneyShort($plan->premium));

        return new HtmlString(
            '<p class="text-sm text-gray-700 dark:text-gray-300">'.e(__('members.back_dues.intro', [
                'count' => Display::digits(count($plan->months)),
                'from' => Display::yearMonth($plan->duesStart),
            ])).'</p>'
            .'<table class="mt-2 w-full text-sm"><tbody>'.$rows.'</tbody><tfoot><tr class="border-t border-gray-300 font-semibold dark:border-white/20">'
            .'<td class="py-1">'.e(__('members.back_dues.total')).'</td><td class="py-1 text-end tabular-nums">'.e(Display::moneyShort($plan->total())).'</td></tr></tfoot></table>'
            .'<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">'.e(__('members.back_dues.note')).'</p>'
        );
    }

    /**
     * One line for the T2 confirmation: "৳ ৫,৮০০ (৫ মাস আগের চাঁদা সহ)".
     *
     * @param  array<string, mixed>  $state
     */
    public static function summary(array $state): ?string
    {
        $plan = self::plan($state);

        if (! $plan instanceof BackDuesPlan || ! $plan->hasBackMonths()) {
            return null;
        }

        return __('members.back_dues.summary', ['total' => Display::moneyShort($plan->total()), 'count' => Display::digits(count($plan->months))]);
    }

    private static function row(string $label, string $amount): string
    {
        return '<tr><td class="py-0.5">'.e($label).'</td><td class="py-0.5 text-end tabular-nums">'.e($amount).'</td></tr>';
    }

    private static function month(mixed $value): ?YearMonth
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? YearMonth::parse($value) : null;
    }
}
