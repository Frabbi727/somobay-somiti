<div style="display: grid; gap: 1.25rem;">
    @include('filament.confirmations.change-summary', ['rows' => $rows, 'showOld' => false])

    <div>
        <h3 style="font-weight: 600; margin-bottom: .5rem;">{{ __('rates.impact.title') }}</h3>
        @include('filament.rates.impact', ['preview' => $preview])
    </div>
</div>
