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

    @if ($acceptance)
        @php($bn = app()->getLocale() === 'bn')
        <x-filament::section icon="heroicon-o-document-check" :heading="__('terms.portal.profile_row', ['version' => Display::digits((string) $acceptance->terms->version), 'date' => Display::date($acceptance->accepted_at)])">
            <details class="text-sm">
                <summary class="cursor-pointer font-medium">{{ __('terms.portal.read_rules') }}</summary>
                <div class="mt-3 space-y-4 whitespace-pre-line leading-relaxed">
                    <div>{{ $bn ? $acceptance->terms->rules_bn : $acceptance->terms->rules_en }}</div>
                    <div>{{ $bn ? $acceptance->terms->declaration_bn : $acceptance->terms->declaration_en }}</div>
                </div>
            </details>
        </x-filament::section>
    @endif

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
