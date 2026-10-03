<?php

declare(strict_types=1);

namespace App\Reports;

use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RateImpactPreviewer;
use App\Support\Spreadsheet\Workbook;

/**
 * The impact preview as an Excel workbook, with every member (the modal shows the first ten).
 */
final class RateImpactDocument
{
    public function __construct(private readonly RateImpactPreviewer $previewer) {}

    public function filename(RatePlan $plan): string
    {
        return 'rate-impact-'.$plan->code.'.xlsx';
    }

    public function excel(RatePlan $plan): string
    {
        $preview = $this->previewer->preview($plan);

        $book = (new Workbook(__('rates.impact.title')))
            ->title([(string) config('app.name')])
            ->title([__('rates.impact.title').' · '.$plan->code])
            ->blank()
            ->row([__('rates.impact.compared_with'), $preview->previous->code ?? __('rates.impact.no_previous')])
            ->row([__('rates.impact.members'), $preview->memberCount()])
            ->row([__('rates.impact.shares'), $preview->shareCount()])
            ->row([__('rates.impact.old_monthly'), $preview->oldMonthlyTotal()])
            ->row([__('rates.impact.new_monthly'), $preview->newMonthlyTotal()])
            ->row([__('rates.impact.difference'), $preview->monthlyDifference()])
            ->row([__('rates.impact.shortfall'), $preview->totalShortfall()]);

        foreach ($preview->warnings as $warning) {
            $book->row(['⚠ '.__($warning['key'], $warning['params'])]);
        }

        $book->blank()->row([
            __('rates.impact.member'),
            __('rates.impact.shares'),
            __('rates.impact.old_monthly'),
            __('rates.impact.new_monthly'),
            __('rates.impact.advance'),
            __('rates.impact.months_old'),
            __('rates.impact.months_new'),
            __('rates.impact.shortfall'),
        ], bold: true);

        foreach ($preview->members as $member) {
            $book->row([
                $member->memberNo.' · '.$member->name,
                $member->shares,
                $member->oldMonthly,
                $member->newMonthly,
                $member->advance,
                $member->coverageOld(),
                $member->coverageNew(),
                $member->shortfall(),
            ]);
        }

        return $book->toBinary();
    }
}
