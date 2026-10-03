<div class="mx-auto max-w-lg space-y-4">
    <h1 class="text-xl font-semibold">{{ __('portal.submit.heading') }}</h1>
    <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('portal.submit.help') }}</p>

    @if ($done)
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300" role="status">{{ $done }}</p>
    @endif
    @if ($error)
        <p class="rounded-lg bg-red-50 px-3 py-2 text-red-700 dark:bg-red-950 dark:text-red-300" role="alert">{{ $error }}</p>
    @endif

    <form wire:submit="submit" class="space-y-4 rounded-xl bg-white p-5 shadow-sm dark:bg-zinc-900">
        <div>
            <span class="mb-1 block text-sm font-medium">{{ __('portal.submit.method') }}</span>
            <div class="flex gap-2">
                @foreach ($methods as $option)
                    <label class="flex items-center gap-2 rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700">
                        <input type="radio" wire:model="method" value="{{ $option->value }}"> {{ $option->getLabel() }}
                    </label>
                @endforeach
            </div>
        </div>
        @foreach ([['amount', 'text', 'decimal'], ['trxId', 'text', 'text'], ['receivedOn', 'date', 'text']] as [$field, $type, $mode])
            <div>
                <label class="mb-1 block text-sm font-medium" for="{{ $field }}">{{ __(['amount' => 'portal.submit.amount', 'trxId' => 'portal.submit.trx', 'receivedOn' => 'portal.submit.date'][$field]) }}</label>
                <input id="{{ $field }}" type="{{ $type }}" inputmode="{{ $mode }}" wire:model="{{ $field }}" class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                @error($field) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <div>
            <label class="mb-1 block text-sm font-medium" for="proof">{{ __('portal.submit.proof') }}</label>
            <input id="proof" type="file" accept="image/*,application/pdf" wire:model="proof">
            <div wire:loading wire:target="proof" class="mt-1 h-2 w-24 animate-pulse rounded bg-zinc-200 dark:bg-zinc-700"></div>
            @error('proof') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <x-portal.confirm :message="__('portal.submit.confirm')" :confirm="__('portal.submit.send')" :cancel="__('portal.submit.cancel')">
            {{ __('portal.submit.send') }}
        </x-portal.confirm>
    </form>
</div>
