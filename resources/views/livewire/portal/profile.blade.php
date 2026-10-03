@php use App\Filament\Support\Display; @endphp

<div class="space-y-6">
    <section class="rounded-xl bg-white p-5 shadow-sm dark:bg-zinc-900">
        <h2 class="mb-3 font-semibold">{{ __('portal.profile.details') }}</h2>
        <dl class="grid gap-2 text-sm sm:grid-cols-2">
            <div><dt class="text-zinc-500">{{ __('members.member.member_no') }}</dt><dd>{{ Display::digits($member->member_no) }}</dd></div>
            <div><dt class="text-zinc-500">{{ __('members.member.name') }}</dt><dd>{{ $member->name_bn }} · {{ $member->name_en }}</dd></div>
            <div><dt class="text-zinc-500">{{ __('members.member.mobile') }}</dt><dd>{{ Display::digits($member->mobile) }}</dd></div>
            <div><dt class="text-zinc-500">{{ __('members.member.joined_on') }}</dt><dd>{{ Display::date($member->joined_on) }}</dd></div>
        </dl>
    </section>

    <section class="rounded-xl bg-white p-5 shadow-sm dark:bg-zinc-900">
        <h2 class="mb-3 font-semibold">{{ __('portal.profile.nominees') }}</h2>
        @forelse ($member->nominees as $nominee)
            <p class="text-sm">{{ $nominee->name }} ({{ $nominee->relation }}) · {{ $nominee->share()->format(app()->getLocale()) }}</p>
        @empty
            <p class="text-sm text-zinc-500">—</p>
        @endforelse
    </section>

    <section class="rounded-xl bg-white p-5 shadow-sm dark:bg-zinc-900">
        <h2 class="mb-1 font-semibold">{{ __('portal.profile.password') }}</h2>
        <p class="mb-3 text-sm text-zinc-500">{{ __('portal.profile.password_help') }}</p>
        @if ($saved)
            <p class="mb-3 text-sm text-emerald-700" role="status">{{ $saved }}</p>
        @endif
        <form wire:submit="setPassword" class="space-y-3">
            <input type="password" autocomplete="new-password" wire:model="password" placeholder="{{ __('portal.login.password') }}" class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
            <input type="password" autocomplete="new-password" wire:model="password_confirmation" placeholder="{{ __('portal.profile.confirm') }}" class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
            @error('password') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            <button class="rounded-lg bg-emerald-600 px-4 py-2 font-medium text-white">{{ __('portal.profile.save') }}</button>
        </form>
    </section>
</div>
