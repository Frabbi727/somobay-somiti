@php use App\Filament\Support\Display; @endphp

<x-filament-panels::page>
    @include('reports.partials.styles')

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section :heading="__('dues.generate.preview')">
        @if ($error)
            <p class="rpt-status-bad">{{ $error }}</p>
        @elseif ($preview)
            <table class="rpt">
                <tbody>
                    <tr><th>{{ __('dues.generate.plan') }}</th><td class="num">{{ $preview->plan->code }}</td></tr>
                    <tr><th>{{ __('dues.generate.members') }}</th><td class="num">{{ Display::digits($preview->memberCount) }}</td></tr>
                    <tr><th>{{ __('dues.generate.shares') }}</th><td class="num">{{ Display::digits($preview->shareCount) }}</td></tr>
                    <tr><th>{{ __('dues.generate.deposits') }}</th><td class="num">{{ Display::digits($preview->depositCount) }} · {{ Display::money($preview->depositTotal) }}</td></tr>
                    <tr><th>{{ __('dues.generate.services') }}</th><td class="num">{{ Display::digits($preview->serviceCount) }} · {{ Display::money($preview->serviceTotal) }}</td></tr>
                    <tr><th>{{ __('dues.generate.existing') }}</th><td class="num">{{ Display::digits($preview->alreadyExisting) }}</td></tr>
                </tbody>
                <tfoot>
                    <tr><td>{{ __('dues.generate.total') }}</td><td class="num">{{ Display::money($preview->newTotal()) }}</td></tr>
                </tfoot>
            </table>
            @if ($preview->newCount() === 0)
                <p class="rpt-status-ok">{{ __('dues.generate.nothing_new') }}</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
