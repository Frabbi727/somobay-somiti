<x-filament-panels::page>
    @include('reports.partials.styles')

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section :heading="$heading">
        @if ($report === null)
            <p class="rpt muted">{{ __('reports.ledger.pick_account') }}</p>
        @else
            <div style="overflow-x: auto;">
                @include('reports.partials.ledger', ['report' => $report])
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
