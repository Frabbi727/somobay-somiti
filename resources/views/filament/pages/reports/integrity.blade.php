<x-filament-panels::page>
    <x-filament::section>
        @if ($run === null)
            <p>{{ __('integrity.never_run') }}</p>
        @else
            <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: center;">
                <x-filament::badge :color="$run->status->getColor()" size="lg">
                    {{ $run->status->getLabel() }}
                </x-filament::badge>
                <span>{{ __('integrity.summary', [
                    'time' => \App\Filament\Support\Display::dateTime($run->started_at),
                    'checks' => \App\Filament\Support\Display::digits($run->checks_run),
                    'findings' => \App\Filament\Support\Display::digits($run->findings_count),
                ]) }}</span>
            </div>
        @endif
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
