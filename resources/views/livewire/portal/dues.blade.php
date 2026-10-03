@php use App\Filament\Support\Display; @endphp

<div class="space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">{{ __('portal.nav.dues') }}</h1>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="openOnly"> {{ __('portal.dues.open_only') }}</label>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm dark:bg-zinc-900">
        <table class="w-full text-sm" wire:loading.class="opacity-50">
            <thead class="text-left text-zinc-500">
                <tr>
                    <th class="px-4 py-2">{{ __('dues.month') }}</th>
                    <th class="px-4 py-2">{{ __('dues.type') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('dues.amount') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('dues.outstanding') }}</th>
                    <th class="px-4 py-2">{{ __('dues.status_label') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($dues as $due)
                    <tr class="border-t border-zinc-100 dark:border-zinc-800">
                        <td class="px-4 py-2">{{ Display::yearMonth($due->month) }}</td>
                        <td class="px-4 py-2">{{ $due->type->getLabel() }}</td>
                        <td class="px-4 py-2 text-right">{{ Display::money($due->amount_poisha) }}</td>
                        <td class="px-4 py-2 text-right font-medium">{{ Display::money($due->outstanding_poisha) }}</td>
                        <td class="px-4 py-2">{{ $due->status->getLabel() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-zinc-500">{{ __('portal.dues.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $dues->links() }}
</div>
