@props(['message', 'confirm', 'cancel'])

{{-- Alpine confirmation modal: wraps a submit button; the form submits only after "confirm". --}}
<div x-data="{ open: false }" class="inline">
    <button type="button" x-on:click="open = true" {{ $attributes->merge(['class' => 'rounded-lg bg-emerald-600 px-4 py-2 font-medium text-white hover:bg-emerald-700']) }}>
        {{ $slot }}
    </button>

    <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" x-on:keydown.escape.window="open = false">
        <div class="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl dark:bg-zinc-900" x-on:click.outside="open = false">
            <p class="mb-4 font-medium">{{ $message }}</p>
            <div class="flex justify-end gap-2">
                <button type="button" x-on:click="open = false" class="rounded-lg border border-zinc-300 px-4 py-2 dark:border-zinc-700">{{ $cancel }}</button>
                <button type="submit" x-on:click="open = false" class="rounded-lg bg-emerald-600 px-4 py-2 font-medium text-white">{{ $confirm }}</button>
            </div>
        </div>
    </div>
</div>
