<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Support;

use App\Domain\Contributions\LateEntry\LateEntryDues;
use App\Domain\Members\Services\ShareChanger;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Filament\Support\Display;
use App\Support\Time\YearMonth;
use Throwable;

/**
 * The line staff see before saving a member entered late ("Already billed for Oct, Nov 2026: …"),
 * built from the same LateEntryDues that writes the dues, so what they see is what is billed.
 */
final class LateEntryPreview
{
    public static function summary(mixed $shares, mixed $from): string
    {
        $count = is_numeric($shares) ? (int) $shares : 0;

        if ($count < 1 || ! is_string($from) || preg_match('/^\d{4}-\d{2}$/', $from) !== 1) {
            return '';
        }

        try {
            $month = YearMonth::parse($from);
            app(ShareChanger::class)->assertJoinMonthAllowed($month);
            $dues = app(LateEntryDues::class);
            $months = $dues->months($month);

            if ($months === []) {
                return '';
            }

            return __('members.late_entry.summary', [
                'months' => implode(', ', array_map(fn (YearMonth $month): string => Display::yearMonth($month), $months)),
                'total' => Display::money($dues->total($count, $month)),
            ]);
        } catch (DomainRuleViolation $violation) {
            return $violation->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            return __('errors.preview_unavailable');
        }
    }
}
