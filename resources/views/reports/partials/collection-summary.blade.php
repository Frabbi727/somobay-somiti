@php use App\Filament\Support\Display; @endphp
<table class="rpt">
    <thead>
        <tr>
            <th>{{ __('dues.month') }}</th>
            <th class="num">{{ __('reports.collection.members') }}</th>
            <th class="num">{{ __('reports.collection.charged') }}</th>
            <th class="num">{{ __('reports.collection.paid') }}</th>
            <th class="num">{{ __('dues.outstanding') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ Display::yearMonth($row['month']) }}</td>
                <td class="num">{{ Display::digits($row['members']) }}</td>
                <td class="num">{{ Display::money($row['charged']) }}</td>
                <td class="num">{{ Display::money($row['paid']) }}</td>
                <td class="num">{{ Display::money($row['outstanding']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">—</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="2">{{ __('journal.line.total') }}</td>
            <td class="num">{{ Display::money($charged) }}</td>
            <td class="num">{{ Display::money($paid) }}</td>
            <td class="num">{{ Display::money($outstanding) }}</td>
        </tr>
    </tfoot>
</table>
