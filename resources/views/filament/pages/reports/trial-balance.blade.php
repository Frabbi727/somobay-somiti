<x-filament-panels::page>
    @include('reports.partials.styles')

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section :heading="$heading">
        @include('reports.partials.trial-balance', ['report' => $report])
    </x-filament::section>

    <x-filament::section :heading="__('reports.reconciliation.title')">
        @include('reports.partials.reconciliation', ['checks' => $checks])
    </x-filament::section>
</x-filament-panels::page>
