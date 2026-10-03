@php use App\Filament\Support\Display; @endphp
<table class="rpt">
    <tbody>
        <tr><th colspan="2">{{ __('reports.receipts_payments.opening') }}</th><td class="num"><strong>{{ Display::money($opening) }}</strong></td></tr>
        @include('reports.partials.account-section', ['heading' => __('reports.receipts_payments.receipts'), 'rows' => $receipts, 'total' => $total_receipts, 'totalLabel' => __('reports.receipts_payments.total_receipts')])
        @include('reports.partials.account-section', ['heading' => __('reports.receipts_payments.payments'), 'rows' => $payments, 'total' => $total_payments, 'totalLabel' => __('reports.receipts_payments.total_payments')])
    </tbody>
    <tfoot>
        <tr><td colspan="2">{{ __('reports.receipts_payments.closing') }}</td><td class="num">{{ Display::money($closing) }}</td></tr>
    </tfoot>
</table>
