<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Support;

use App\Domain\Contributions\BackDues\BackDuesPlan;
use App\Domain\Contributions\BackDues\BackDuesPlanner;
use App\Domain\Contributions\LateEntry\LateEntryDues;
use App\Domain\Members\Services\ShareChanger;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Filament\Support\Display;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * "Billed at joining" on the New Member form and the final registration approval: every amount a
 * new member owes on the day they are entered, in one list with one total. It is built from the
 * same BackDuesPlanner and LateEntryDues that write the dues, so what staff see is what is billed:
 *
 *  - months before joining (back-dues, only for a late joiner), plus the entry premium;
 *  - months from joining that were already billed (late data entry), as normal dues;
 *  - the registration fee.
 */
final class JoiningBillPreview
{
    /**
     * @param  array<string, mixed>  $state  shares / effective_from / dues_start_month
     * @return array{back: BackDuesPlan, billed: list<array{month: YearMonth, deposit: Money, serviceCharge: Money}>}|string|null
     */
    public static function bill(array $state): array|string|null
    {
        $shares = is_numeric($state['shares'] ?? null) ? (int) $state['shares'] : 0;
        $shareStart = self::month($state['effective_from'] ?? null);
        $duesStart = self::month($state['dues_start_month'] ?? null) ?? $shareStart;

        if ($shares < 1 || $shareStart === null || $duesStart === null) {
            return null;
        }

        try {
            app(ShareChanger::class)->assertJoinMonthAllowed($shareStart);

            return [
                'back' => app(BackDuesPlanner::class)->plan($shares, $duesStart, $shareStart),
                'billed' => app(LateEntryDues::class)->bill($shares, $shareStart),
            ];
        } catch (DomainRuleViolation $violation) {
            return $violation->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            return __('errors.preview_unavailable');
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function html(array $state): HtmlString
    {
        $bill = self::bill($state);

        if ($bill === null) {
            return new HtmlString('');
        }

        if (is_string($bill)) {
            return new HtmlString('<p class="text-sm font-medium text-danger-600 dark:text-danger-400">'.e($bill).'</p>');
        }

        ['back' => $back, 'billed' => $billed] = $bill;
        $body = '';
        $notes = [];

        if ($back->hasBackMonths()) {
            $body .= self::group(__('members.back_dues.before_heading'), $back->months);
            $notes[] = __('members.back_dues.note');
        }

        if ($billed !== []) {
            $body .= self::group(__('members.back_dues.billed_heading'), $billed);
            $notes[] = __('members.back_dues.billed_note');
        }

        if ($notes === []) {
            $notes[] = __('members.back_dues.none');
        }

        $body .= '<tbody>'.self::row(__('members.back_dues.registration'), $back->registrationFee);

        if ($back->premium->isPositive()) {
            $body .= self::row(
                __('members.back_dues.premium_label'),
                $back->premium,
                __('members.back_dues.premium_detail', ['rate' => $back->premiumRate->format(app()->getLocale())]),
            );
        }

        $body .= '</tbody>';

        $notesHtml = implode('', array_map(fn (string $note): string => '<p>'.e($note).'</p>', $notes));

        return new HtmlString(
            '<table class="w-full text-sm text-gray-950 dark:text-white">'.$body
            .'<tfoot><tr class="border-t border-gray-950/10 dark:border-white/20">'
            .'<th scope="row" class="pt-2 text-start font-semibold">'.e(__('members.back_dues.total')).'</th>'
            .'<td class="pt-2 text-end font-semibold tabular-nums whitespace-nowrap">'.e(Display::moneyShort(self::total($back, $billed))).'</td>'
            .'</tr></tfoot></table>'
            .'<div class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-400">'.$notesHtml.'</div>'
        );
    }

    /**
     * One line for the confirmation dialog: "To pay at joining: ৳ 7,000."
     *
     * @param  array<string, mixed>  $state
     */
    public static function summary(array $state): ?string
    {
        $bill = self::bill($state);

        if (! is_array($bill)) {
            return null;
        }

        return __('members.back_dues.summary', ['total' => Display::moneyShort(self::total($bill['back'], $bill['billed']))]);
    }

    /**
     * @param  list<array{month: YearMonth, deposit: Money, serviceCharge: Money}>  $billed
     */
    private static function total(BackDuesPlan $back, array $billed): Money
    {
        return $back->total()->plus(Money::sum(array_map(fn (array $row): Money => $row['deposit']->plus($row['serviceCharge']), $billed)));
    }

    /**
     * A titled block of months: each month's total, with its deposit and service charge beneath.
     *
     * @param  array<int, array{month: YearMonth, deposit: Money, serviceCharge: Money}>  $months
     */
    private static function group(string $heading, array $months): string
    {
        $rows = '';

        foreach ($months as $row) {
            $rows .= self::row(
                Display::yearMonth($row['month']),
                $row['deposit']->plus($row['serviceCharge']),
                __('members.back_dues.month_amounts', [
                    'deposit' => Display::moneyShort($row['deposit']),
                    'service' => Display::moneyShort($row['serviceCharge']),
                ]),
            );
        }

        return '<tbody><tr><th scope="rowgroup" colspan="2" class="pt-3 pb-1 text-start text-xs font-medium text-gray-600 dark:text-gray-400">'
            .e($heading).'</th></tr>'.$rows.'</tbody>';
    }

    private static function row(string $label, Money $amount, ?string $detail = null): string
    {
        $detailHtml = $detail === null ? '' : '<span class="block text-xs text-gray-600 dark:text-gray-400">'.e($detail).'</span>';

        return '<tr class="align-top"><td class="py-1 pe-4">'.e($label).$detailHtml.'</td>'
            .'<td class="py-1 text-end tabular-nums whitespace-nowrap">'.e(Display::moneyShort($amount)).'</td></tr>';
    }

    private static function month(mixed $value): ?YearMonth
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1 ? YearMonth::parse($value) : null;
    }
}
