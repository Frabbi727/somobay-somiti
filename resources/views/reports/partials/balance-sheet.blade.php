@php use App\Filament\Support\Display; @endphp
<table class="rpt">
    <tbody>
        @include('reports.partials.account-section', ['heading' => __('reports.balance.assets'), 'rows' => $assets, 'total' => $total_assets, 'totalLabel' => __('reports.balance.total_assets')])
        @include('reports.partials.account-section', ['heading' => __('reports.balance.liabilities'), 'rows' => $liabilities])
        @include('reports.partials.account-section', ['heading' => __('reports.balance.equity'), 'rows' => $equity])
        <tr><td></td><td>{{ __('reports.balance.surplus') }}</td><td class="num">{{ Display::money($surplus) }}</td></tr>
    </tbody>
    <tfoot>
        <tr><td colspan="2">{{ __('reports.balance.total_liabilities_equity') }}</td><td class="num">{{ Display::money($total_liabilities_equity) }}</td></tr>
    </tfoot>
</table>
<p class="{{ $total_assets->equals($total_liabilities_equity) ? 'rpt-status-ok' : 'rpt-status-bad' }}">
    {{ $total_assets->equals($total_liabilities_equity) ? __('reports.balance.balanced') : __('reports.trial_balance.out_of_balance', ['difference' => Display::money($total_assets->minus($total_liabilities_equity)->absolute())]) }}
</p>
