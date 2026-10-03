@php use App\Filament\Support\Display; @endphp

<div class="space-y-6">
    <h1 class="text-2xl font-semibold">{{ __('portal.dashboard.welcome', ['name' => app()->getLocale() === 'bn' ? $member->name_bn : $member->name_en]) }}</h1>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">{{ __('portal.dashboard.savings') }}</p>
            <p class="text-xl font-semibold">{{ Display::money($savings) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">{{ __('portal.dashboard.paid_through') }}</p>
            <p class="text-xl font-semibold">{{ $paidThrough === null ? __('portal.dashboard.not_paid_yet') : Display::yearMonth($paidThrough) }}</p>
            @if ($estimate > 0)
                <p class="text-xs text-zinc-500">{{ __('portal.dashboard.estimate', ['count' => Display::digits($estimate)]) }}</p>
            @endif
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">{{ __('portal.dashboard.outstanding') }}</p>
            <p @class(['text-xl font-semibold', 'text-red-600 dark:text-red-400' => $outstanding->isPositive()])>{{ Display::money($outstanding) }}</p>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">{{ __('portal.dashboard.advance') }}</p>
            <p class="text-xl font-semibold">{{ Display::money($advance) }}</p>
            <p class="text-xs text-zinc-500">{{ __('portal.dashboard.shares') }}: {{ Display::digits($shares) }}</p>
        </div>
    </div>

    @if ($outstanding->isPositive())
        <a href="{{ route('portal.submit') }}" wire:navigate class="inline-block rounded-lg bg-emerald-600 px-4 py-2 font-medium text-white">{{ __('portal.dashboard.pay_now') }}</a>
    @endif

    <section class="rounded-xl bg-white p-4 shadow-sm dark:bg-zinc-900">
        <h2 class="mb-3 font-semibold">{{ __('portal.dashboard.recent') }}</h2>
        @forelse ($payments as $payment)
            <div class="flex items-center justify-between border-t border-zinc-100 py-2 text-sm first:border-0 dark:border-zinc-800">
                <span>{{ Display::date($payment->received_on) }} · {{ $payment->method->getLabel() }}</span>
                <span class="font-medium">{{ Display::money($payment->amount_poisha) }} · {{ $payment->status->getLabel() }}</span>
            </div>
        @empty
            <p class="text-sm text-zinc-500">{{ __('portal.dashboard.none') }}</p>
        @endforelse
    </section>
</div>
