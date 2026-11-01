<x-filament-panels::page>
    <x-filament::section :heading="__('terms.portal.not_yet')" :description="__('terms.portal.please_read')" icon="heroicon-o-document-text">
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('terms.portal.title') }} · v{{ $shownVersion }}</p>
    </x-filament::section>

    <x-filament::section :heading="__('terms.portal.rules_heading')">
        <div class="whitespace-pre-line text-sm leading-relaxed">{{ $rules }}</div>
    </x-filament::section>

    <x-filament::section :heading="__('terms.portal.declaration_heading')">
        <div class="whitespace-pre-line text-sm leading-relaxed">{{ $declaration }}</div>
    </x-filament::section>

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>
    <div>
        {{ $this->acceptAction }}
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
