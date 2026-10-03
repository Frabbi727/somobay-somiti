@php
    use App\Domain\Contributions\Enums\PaymentStatus;
    use App\Filament\Support\Display;
    use App\Reports\ReceiptDocument;
@endphp

<div class="space-y-4">
    <h1 class="text-xl font-semibold">{{ __('portal.nav.receipts') }}</h1>

    <div class="space-y-2">
        @forelse ($payments as $payment)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white p-4 shadow-sm dark:bg-zinc-900">
                <div>
                    <p class="font-medium">{{ Display::money($payment->amount_poisha) }} · {{ $payment->method->getLabel() }}</p>
                    <p class="text-sm text-zinc-500">{{ Display::date($payment->received_on) }} · {{ Display::digits($payment->journalEntry->voucher_no ?? '') }}</p>
                </div>
                @if ($payment->status === PaymentStatus::Approved)
                    <a href="{{ ReceiptDocument::signedUrl($payment) }}" target="_blank" rel="noopener" class="rounded-lg border border-emerald-600 px-3 py-1.5 text-sm text-emerald-700 dark:text-emerald-400">{{ __('portal.receipts.download') }}</a>
                @elseif ($payment->status === PaymentStatus::Pending)
                    <span class="text-sm text-amber-600">{{ __('portal.receipts.pending') }}</span>
                @else
                    <span class="text-sm text-zinc-500">{{ $payment->status->getLabel() }}</span>
                @endif
            </div>
        @empty
            <p class="text-zinc-500">{{ __('portal.receipts.none') }}</p>
        @endforelse
    </div>

    {{ $payments->links() }}
</div>
