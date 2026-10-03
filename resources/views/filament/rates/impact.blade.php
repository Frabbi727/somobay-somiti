@php
    use App\Filament\Support\Display;
    /** @var \App\Domain\Settings\Reports\RateImpactPreview $preview */
    $withAdvance = $preview->membersWithAdvance();
    $shown = array_slice($withAdvance, 0, 10);
    $difference = $preview->monthlyDifference();
@endphp

@include('reports.partials.styles')

<div style="display: grid; gap: 1rem;">
    @foreach ($preview->warnings as $warning)
        <div style="padding: .5rem .75rem; border-radius: .5rem; background: rgba(245, 158, 11, .12); color: rgb(180, 83, 9); font-weight: 600;">
            ⚠ {{ __($warning['key'], $warning['params']) }}
        </div>
    @endforeach

    <table class="rpt">
        <tbody>
            <tr>
                <th>{{ __('rates.impact.period') }}</th>
                <td class="num">
                    {{ $preview->until === null
                        ? __('rates.impact.period_open', ['from' => Display::yearMonth($preview->from)])
                        : __('rates.impact.period_closed', ['from' => Display::yearMonth($preview->from), 'until' => Display::yearMonth($preview->until)]) }}
                </td>
            </tr>
            <tr>
                <th>{{ __('rates.impact.compared_with') }}</th>
                <td class="num">{{ $preview->previous?->code ?? __('rates.impact.no_previous') }}</td>
            </tr>
            <tr>
                <th>{{ __('rates.impact.members') }}</th>
                <td class="num">{{ Display::digits($preview->memberCount()) }}</td>
            </tr>
            <tr>
                <th>{{ __('rates.impact.shares') }}</th>
                <td class="num">{{ Display::digits($preview->shareCount()) }}</td>
            </tr>
            <tr>
                <th>{{ __('rates.impact.old_monthly') }}</th>
                <td class="num">{{ Display::money($preview->oldMonthlyTotal()) }}</td>
            </tr>
            <tr>
                <th>{{ __('rates.impact.new_monthly') }}</th>
                <td class="num">{{ Display::money($preview->newMonthlyTotal()) }}</td>
            </tr>
            <tr>
                <th>{{ __('rates.impact.difference') }}</th>
                <td class="num {{ $difference->isNegative() ? 'rpt-status-bad' : 'rpt-status-ok' }}">
                    {{ $difference->isPositive() ? '+' : '' }}{{ Display::money($difference) }}
                </td>
            </tr>
        </tbody>
    </table>

    <div>
        <strong>{{ __('rates.impact.advance_title') }}</strong>
        @if ($withAdvance === [])
            <p class="muted">{{ __('rates.impact.no_advance') }}</p>
        @else
            <table class="rpt">
                <thead>
                    <tr>
                        <th>{{ __('rates.impact.member') }}</th>
                        <th class="num">{{ __('rates.impact.advance') }}</th>
                        <th class="num">{{ __('rates.impact.months_old') }}</th>
                        <th class="num">{{ __('rates.impact.months_new') }}</th>
                        <th class="num">{{ __('rates.impact.shortfall') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($shown as $member)
                        <tr>
                            <td>{{ Display::digits($member->memberNo) }} · {{ $member->name }}</td>
                            <td class="num">{{ Display::money($member->advance) }}</td>
                            <td class="num">{{ Display::digits($member->coverageOld()) }}</td>
                            <td class="num">{{ Display::digits($member->coverageNew()) }}</td>
                            <td class="num">{{ $member->shortfall()->isZero() ? '' : Display::money($member->shortfall()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if (count($withAdvance) > count($shown))
                <p class="muted">{{ __('rates.impact.advance_more', ['count' => Display::digits(count($withAdvance) - count($shown))]) }}</p>
            @endif
            <p>{{ __('rates.impact.total_shortfall', ['amount' => Display::money($preview->totalShortfall())]) }}</p>
        @endif
    </div>

    @if ($preview->topUpLotCount > 0)
        <p>{{ __('rates.impact.top_up', ['count' => Display::digits($preview->topUpLotCount), 'amount' => Display::money($preview->topUpAmount)]) }}</p>
    @endif

    @if ($preview->lockedDueCount > 0)
        <p>{{ __('rates.impact.locked', ['count' => Display::digits($preview->lockedDueCount), 'amount' => Display::money($preview->lockedDueAmount)]) }}</p>
    @endif
</div>
