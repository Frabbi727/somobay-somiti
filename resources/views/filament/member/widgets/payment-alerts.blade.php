@php
    use App\Domain\Contributions\Enums\PaymentStatus;
    use App\Filament\Support\Display;
@endphp

<x-filament-widgets::widget>
    <div class="space-y-3">
        @if ($pending->isNotEmpty())
            <x-filament::section icon="heroicon-o-clock" icon-color="warning" :heading="__('portal.alerts.pending_heading', ['count' => Display::digits($pending->count())])">
                <p class="text-sm">{{ __('portal.alerts.pending_body', ['amount' => Display::money($pending->first()->amount_poisha), 'date' => Display::date($pending->first()->received_on)]) }}</p>
                <x-filament::link :href="$paymentsUrl" class="mt-2 text-sm">{{ __('portal.dashboard.all_payments') }}</x-filament::link>
            </x-filament::section>
        @endif

        @foreach ($turnedDown as $payment)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger"
                :heading="__($payment->status === PaymentStatus::Rejected ? 'portal.alerts.rejected_heading' : 'portal.alerts.reversed_heading', ['amount' => Display::money($payment->amount_poisha)])">
                <p class="text-sm">{{ __('portal.alerts.reason', ['reason' => $payment->status === PaymentStatus::Rejected ? $payment->rejection_reason : $payment->reversal_reason]) }}</p>
                @if ($payment->status === PaymentStatus::Rejected)
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('portal.alerts.rejected_next') }}</p>
                    <x-filament::link :href="$payUrl" class="mt-2 text-sm">{{ __('portal.dashboard.pay_now') }}</x-filament::link>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('portal.alerts.reversed_next') }}</p>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
