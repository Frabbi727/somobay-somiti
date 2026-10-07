<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $headline }}</x-slot>
        <p>{{ $message }}</p>
        @if ($decision)
            <p class="mt-3 text-sm">
                <span class="font-medium">{{ __('registration.field.reason') }}:</span> {{ $decision->reason }}
            </p>
        @endif
    </x-filament::section>

    <x-filament::section>
        @include('filament.registration.timeline', ['steps' => $steps])
    </x-filament::section>
</x-filament-panels::page>
