@php
    use App\Domain\Members\Portal\MonthState;
    use App\Filament\Support\Display;
    /** @var \App\Domain\Members\Portal\MemberPositionData $position */
    $owes = $position->oweNow->isPositive();
@endphp

<x-filament-widgets::widget>
    <div class="space-y-4">
        {{-- 1. What do I owe? --}}
        <section @class([
            'rounded-xl border p-5',
            'border-danger-300 bg-danger-50 dark:border-danger-500/40 dark:bg-danger-500/10' => $owes,
            'border-success-300 bg-success-50 dark:border-success-500/40 dark:bg-success-500/10' => ! $owes,
        ]) aria-labelledby="owe-now-heading">
            <p id="owe-now-heading" class="text-base font-medium text-gray-700 dark:text-gray-200">{{ __('portal.home.owe_now') }}</p>
            <p @class([
                'mt-1 text-4xl font-bold tabular-nums',
                'text-danger-700 dark:text-danger-300' => $owes,
                'text-success-700 dark:text-success-300' => ! $owes,
            ])>
                @unless ($owes)
                    <x-filament::icon icon="heroicon-o-check-circle" class="inline h-8 w-8 align-[-4px]" aria-hidden="true" />
                @endunless
                {{ Display::moneyShort($position->oweNow) }}
            </p>

            <p class="mt-2 text-base text-gray-800 dark:text-gray-100">
                @if ($position->paidThrough === null)
                    {{ __('portal.home.not_paid_yet') }}
                @else
                    {{ __('portal.home.paid_through', ['month' => Display::yearMonth($position->paidThrough)]) }}
                @endif
            </p>

            @if ($position->otherOwed->isPositive())
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ __('portal.home.other_owed', ['amount' => Display::moneyShort($position->otherOwed)]) }}</p>
            @endif

            @if ($position->comingUp->isPositive())
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ __('portal.home.coming_up', ['amount' => Display::moneyShort($position->comingUp)]) }}</p>
            @endif

            @if ($position->pendingCount > 0)
                <p class="mt-1 text-sm text-warning-700 dark:text-warning-300">{{ __('portal.home.pending', ['count' => Display::digits($position->pendingCount), 'amount' => Display::moneyShort($position->pendingTotal)]) }}</p>
            @endif

            @if ($owes || $position->comingUp->isPositive())
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="$payUrl" size="lg" icon="heroicon-o-device-phone-mobile">{{ __('portal.dashboard.pay_now') }}</x-filament::button>
                    <x-filament::button tag="a" :href="$duesUrl" size="lg" color="gray" outlined>{{ __('portal.home.see_dues') }}</x-filament::button>
                </div>
            @endif
        </section>

        {{-- 2. What have I paid, what do I hold? --}}
        <dl class="grid grid-cols-3 gap-2 text-center">
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('portal.home.paid_total') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ Display::moneyShort($position->paidTotal) }}</dd>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('portal.home.advance') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ Display::moneyShort($position->advance) }}</dd>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('portal.home.savings') }}</dt>
                <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ Display::moneyShort($position->savings) }}</dd>
            </div>
        </dl>

        {{-- 3. Which months are paid? --}}
        <section aria-labelledby="months-heading">
            <h2 id="months-heading" class="text-base font-medium text-gray-700 dark:text-gray-200">{{ __('portal.home.months') }}</h2>
            <ol class="mt-2 grid grid-cols-4 gap-2 sm:grid-cols-6">
                @foreach ($position->months as $row)
                    @php /** @var MonthState $state */ $state = $row['state']; @endphp
                    <li @class([
                        'flex flex-col items-center rounded-lg border px-1 py-2 text-center',
                        'border-success-300 bg-success-50 dark:border-success-500/40 dark:bg-success-500/10' => $state === MonthState::Paid,
                        'border-warning-300 bg-warning-50 dark:border-warning-500/40 dark:bg-warning-500/10' => in_array($state, [MonthState::PartlyPaid, MonthState::NotDueYet], true),
                        'border-danger-300 bg-danger-50 dark:border-danger-500/40 dark:bg-danger-500/10' => $state === MonthState::Unpaid,
                        'border-info-300 bg-info-50 dark:border-info-500/40 dark:bg-info-500/10' => $state === MonthState::Waived,
                        'border-gray-200 bg-white text-gray-400 dark:border-white/10 dark:bg-transparent' => in_array($state, [MonthState::Cancelled, MonthState::BeforeStart, MonthState::NotBilled], true),
                    ]) aria-label="{{ Display::yearMonth($row['month']) }}: {{ $state->getLabel() }}{{ $row['owed']->isPositive() ? ', '.Display::moneyShort($row['owed']) : '' }}">
                        <span class="text-sm font-medium">{{ Display::monthShort($row['month']) }}</span>
                        <x-filament::icon :icon="$state->getIcon()" class="my-1 h-5 w-5" aria-hidden="true" />
                        <span class="text-xs leading-tight">{{ $row['owed']->isPositive() ? Display::moneyShort($row['owed']) : $state->getLabel() }}</span>
                    </li>
                @endforeach
            </ol>
        </section>

        @if ($position->monthlyAmount !== null)
            <p class="text-sm text-gray-700 dark:text-gray-300">
                {{ __('portal.home.monthly_amount', ['amount' => Display::moneyShort($position->monthlyAmount), 'count' => Display::digits($position->shares)]) }}
            </p>
        @endif

        @if ($position->advanceCoversMonths > 0)
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('portal.dashboard.estimate', ['count' => Display::digits($position->advanceCoversMonths)]) }}</p>
        @endif
    </div>
</x-filament-widgets::widget>
