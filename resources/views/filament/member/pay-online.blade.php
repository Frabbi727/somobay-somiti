<x-filament-panels::page>
    <x-filament::section :description="__('portal.submit.help')">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
    </x-filament::section>
</x-filament-panels::page>
