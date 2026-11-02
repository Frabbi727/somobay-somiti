@php use App\Filament\Support\Display; @endphp

<x-filament-panels::page>
    @include('reports.partials.styles')

    <x-filament::section :heading="__('financial_settings.in_force')">
        <p class="rpt muted" style="margin-bottom: .5rem;">
            @if ($version)
                {{ __('financial_settings.in_force_since', ['number' => Display::digits((string) $version->id), 'name' => $version->decider?->name ?? '—', 'date' => Display::dateTime($version->decided_at)]) }}
            @else
                {{ __('financial_settings.in_force_defaults') }}
            @endif
        </p>
        <table class="rpt">
            <tbody>
                @foreach ($current as $key => $value)
                    <tr>
                        <th>{{ __('financial_settings.field.'.$key) }}</th>
                        <td>{{ $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    @if ($pending)
        <x-filament::section>
            <p class="rpt-status-bad">{{ __('financial_settings.pending_note') }}</p>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('financial_settings.propose_heading')" :description="__('financial_settings.propose_help')">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
