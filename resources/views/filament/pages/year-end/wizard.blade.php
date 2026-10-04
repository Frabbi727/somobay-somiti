@php use App\Filament\Support\Display; use App\Filament\Pages\YearEnd\YearEndWizard; @endphp

<x-filament-panels::page>
    @include('reports.partials.styles')

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section :heading="__('year_end.wizard.checklist')">
        @if ($blocker)
            <p class="rpt-status-bad">{{ $blocker }}</p>
        @else
            <p class="rpt-status-ok">{{ __('year_end.wizard.ready') }}</p>
        @endif
        <p class="rpt muted">{{ __('year_end.wizard.adjustments_hint') }}</p>
    </x-filament::section>

    <x-filament::section :heading="__('year_end.wizard.preview')">
        @if ($error)
            <p class="rpt-status-bad">{{ $error }}</p>
        @elseif ($figures)
            <table class="rpt">
                <tbody>
                    @foreach (YearEndWizard::summaryValues($figures) as $key => $value)
                        <tr><th>{{ YearEndWizard::summaryLabels()[$key] }}</th><td class="num">{{ $value }}</td></tr>
                    @endforeach
                    <tr><th>{{ __('year_end.field.prior_loss') }}</th><td class="num">{{ Display::money($figures->priorLoss) }}</td></tr>
                    <tr><th>{{ __('year_end.field.share_months') }}</th><td class="num">{{ Display::digits($figures->totalShareMonths()) }}</td></tr>
                </tbody>
            </table>
            @if (! $figures->isProfit())
                <p class="rpt muted">{{ __('year_end.wizard.loss_year') }}</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
