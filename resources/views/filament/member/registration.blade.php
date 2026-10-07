<x-filament-panels::page>
    @if ($returned)
        <x-filament::section icon="heroicon-o-arrow-uturn-left" icon-color="warning">
            <x-slot name="heading">{{ $headline }}</x-slot>
            <p class="text-sm">
                <span class="font-medium">{{ __('registration.field.reason') }}:</span> {{ $returned->reason }}
            </p>
        </x-filament::section>
    @endif

    <form wire:submit="submit">
        {{ $this->form }}
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
