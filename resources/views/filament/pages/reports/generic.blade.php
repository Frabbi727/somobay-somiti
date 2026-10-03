<x-filament-panels::page>
    @include('reports.partials.styles')

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section :heading="$data['heading'] ?? null">
        @if ($data === null)
            <p class="rpt muted">{{ __('reports.choose_filters') }}</p>
        @else
            <div style="overflow-x: auto;">
                @include($partial, $data)
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
