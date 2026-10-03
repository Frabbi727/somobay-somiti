@php use App\Filament\Support\Display; @endphp
<table class="rpt">
    <tbody>
        @include('reports.partials.account-section', ['heading' => __('reports.income.income'), 'rows' => $income, 'total' => $total_income, 'totalLabel' => __('reports.income.total_income')])
        @include('reports.partials.account-section', ['heading' => __('reports.income.expense'), 'rows' => $expense, 'total' => $total_expense, 'totalLabel' => __('reports.income.total_expense')])
    </tbody>
    <tfoot>
        <tr>
            <td colspan="2">{{ __('reports.income.surplus') }}</td>
            <td class="num {{ $surplus->isNegative() ? 'rpt-status-bad' : 'rpt-status-ok' }}">{{ Display::money($surplus) }}</td>
        </tr>
    </tfoot>
</table>
