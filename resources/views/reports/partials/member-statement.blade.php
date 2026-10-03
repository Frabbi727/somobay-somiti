@php use App\Filament\Support\Display; @endphp
<table class="rpt">
    <thead>
        <tr>
            <th>{{ __('reports.ledger.date') }}</th>
            <th>{{ __('reports.ledger.narration') }}</th>
            <th class="num">{{ __('reports.statement.charge') }}</th>
            <th class="num">{{ __('reports.statement.paid') }}</th>
            <th class="num">{{ __('reports.statement.owed') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr><td></td><td class="muted">{{ __('reports.ledger.opening') }}</td><td></td><td></td><td class="num">{{ Display::money($opening) }}</td></tr>
        @foreach ($rows as $row)
            <tr>
                <td>{{ Display::date($row['date']) }}</td>
                <td>{{ Display::digits($row['description']) }}</td>
                <td class="num">{{ $row['charge']->isZero() ? '' : Display::money($row['charge']) }}</td>
                <td class="num">{{ $row['paid']->isZero() ? '' : Display::money($row['paid']) }}</td>
                <td class="num">{{ Display::money($row['balance']) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="2">{{ __('reports.ledger.closing') }}</td>
            <td class="num">{{ Display::money($total_charges) }}</td>
            <td class="num">{{ Display::money($total_paid) }}</td>
            <td class="num">{{ Display::money($closing) }}</td>
        </tr>
    </tfoot>
</table>
<p class="muted">{{ __('reports.statement.note') }}</p>
