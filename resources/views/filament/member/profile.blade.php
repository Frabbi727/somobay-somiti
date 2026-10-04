@php use App\Filament\Support\Display; @endphp

<x-filament-panels::page>
    <x-filament::section :heading="__('portal.profile.details')" icon="heroicon-o-identification">
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('members.member.member_no') }}</dt><dd class="font-medium">{{ Display::digits($member->member_no) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('members.member.name') }}</dt><dd class="font-medium">{{ $member->name_bn }} · {{ $member->name_en }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('members.member.mobile') }}</dt><dd class="font-medium">{{ Display::digits($member->mobile) }}</dd></div>
            <div><dt class="text-gray-500 dark:text-gray-400">{{ __('members.member.joined_on') }}</dt><dd class="font-medium">{{ Display::date($member->joined_on) }}</dd></div>
        </dl>
    </x-filament::section>

    <x-filament::section :heading="__('portal.profile.nominees')" icon="heroicon-o-users">
        @forelse ($member->nominees as $nominee)
            <p class="text-sm">{{ $nominee->name }} ({{ $nominee->relation }}) · {{ $nominee->share()->format(app()->getLocale()) }}</p>
        @empty
            <p class="text-sm text-gray-500">—</p>
        @endforelse
    </x-filament::section>

    <x-filament::section :heading="__('portal.profile.password')" :description="__('portal.profile.password_help')" icon="heroicon-o-key">
        <form wire:submit.prevent>
            {{ $this->form }}
        </form>
        <div class="mt-4">
            {{ $this->changePasswordAction }}
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
