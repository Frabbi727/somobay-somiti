@extends('reports.pdf.layout')

@php
    use App\Filament\Support\Display;
    /** @var \App\Domain\Contributions\Models\Payment $payment */
@endphp

@section('content')
    <table class="rpt" style="margin-bottom: 10px;">
        <tbody>
            <tr>
                <th>{{ __('payments.field.voucher') }}</th>
                <td>{{ Display::digits($payment->journalEntry->voucher_no ?? '—') }}</td>
                <th>{{ __('payments.field.received_on') }}</th>
                <td>{{ Display::date($payment->received_on) }}</td>
            </tr>
            <tr>
                <th>{{ __('payments.receipt.received_from') }}</th>
                <td colspan="3">{{ Display::digits($payment->member->member_no) }} · {{ Display::isBangla() ? $payment->member->name_bn : $payment->member->name_en }} · {{ Display::digits($payment->member->mobile) }}</td>
            </tr>
            <tr>
                <th>{{ __('payments.field.method') }}</th>
                <td>{{ $payment->method->getLabel() }}</td>
                <th>{{ __('payments.field.trx_id') }}</th>
                <td>{{ $payment->trx_id ?? '—' }}</td>
            </tr>
        </tbody>
    </table>

    <table class="rpt">
        <thead>
            <tr>
                <th>{{ __('payments.receipt.month') }}</th>
                <th>{{ __('payments.receipt.for') }}</th>
                <th class="num">{{ __('payments.field.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payment->allocations as $allocation)
                <tr>
                    <td>{{ Display::yearMonth($allocation->due->month) }}</td>
                    <td>{{ $allocation->due->type->getLabel() }}</td>
                    <td class="num">{{ Display::money($allocation->amount_poisha) }}</td>
                </tr>
            @endforeach
            @if ($held->isPositive())
                <tr>
                    <td></td>
                    <td>{{ __('payments.field.advance_held') }}</td>
                    <td class="num">{{ Display::money($held) }}</td>
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">{{ __('payments.receipt.total') }}</td>
                <td class="num">{{ Display::money($payment->amount_poisha) }}</td>
            </tr>
        </tfoot>
    </table>

    <p style="margin-top: 12px;">
        <strong>{{ __('payments.field.paid_through') }}:</strong>
        {{ $paidThrough === null ? __('payments.field.not_paid_yet') : Display::yearMonth($paidThrough) }}
        @if ($estimate > 0)
            · {{ __('payments.field.estimate', ['count' => Display::digits($estimate)]) }}
        @endif
    </p>

    <table width="100%" style="margin-top: 40px; font-size: 9pt;">
        <tr>
            <td>{{ __('payments.field.recorded_by') }}: {{ $payment->recorder->name }}</td>
            <td style="text-align: right;">{{ __('payments.field.approved_by') }}: {{ $payment->approver?->name }}</td>
        </tr>
    </table>
@endsection
