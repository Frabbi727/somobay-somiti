@php use App\Filament\Support\Display; @endphp

<x-filament-panels::page>
    @include('reports.partials.styles')

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @if ($member === null)
        <x-filament::section>
            <p class="rpt muted">{{ __('payments.collect.pick_member') }}</p>
        </x-filament::section>
    @else
        <x-filament::section :heading="$member->displayName()">
            <table class="rpt">
                <tbody>
                    <tr>
                        <th>{{ __('payments.field.paid_through') }}</th>
                        <td class="num">{{ $paidThrough === null ? __('payments.field.not_paid_yet') : Display::yearMonth($paidThrough) }}</td>
                        <th>{{ __('payments.field.advance_balance') }}</th>
                        <td class="num">{{ Display::money($advance) }}</td>
                    </tr>
                </tbody>
            </table>

            <h3 style="font-weight: 600; margin: 1rem 0 .25rem;">{{ __('payments.field.open_dues') }}</h3>
            <table class="rpt">
                <thead>
                    <tr>
                        <th>{{ __('dues.month') }}</th>
                        <th>{{ __('dues.type') }}</th>
                        <th class="num">{{ __('dues.outstanding') }}</th>
                        <th class="num">{{ __('payments.collect.preview') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $planned = collect($preview['allocations'] ?? [])->mapWithKeys(fn ($row) => [$row['due']->id => $row['amount']]);
                    @endphp
                    @forelse ($openDues as $due)
                        <tr>
                            <td>{{ Display::yearMonth($due->month) }}</td>
                            <td>{{ $due->type->getLabel() }}</td>
                            <td class="num">{{ Display::money($due->outstanding_poisha) }}</td>
                            <td class="num rpt-status-ok">{{ $planned->has($due->id) ? Display::money($planned->get($due->id)) : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">—</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">{{ __('payments.field.total_outstanding') }}</td>
                        <td class="num">{{ Display::money(\App\Support\Money\Money::sum($openDues->map(fn ($due) => $due->outstanding_poisha))) }}</td>
                        <td class="num">{{ $preview ? Display::money($preview['to_dues']) : '' }}</td>
                    </tr>
                </tfoot>
            </table>

            @if ($preview)
                <p style="margin-top: .75rem;">
                    {{ __('payments.collect.to_dues', ['amount' => Display::money($preview['to_dues'])]) }}
                    @if ($preview['remainder']->isPositive())
                        ·
                        {{ $preview['covers_through']
                            ? __('payments.collect.to_advance', ['amount' => Display::money($preview['remainder']), 'month' => Display::yearMonth($preview['covers_through'])])
                            : __('payments.collect.to_advance_plain', ['amount' => Display::money($preview['remainder'])]) }}
                    @endif
                </p>
                <p class="muted">{{ __('payments.collect.preview_note') }}</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
