@php use App\Filament\Support\Display; @endphp
<table class="rpt">
    <thead>
        <tr>
            <th>{{ __('investments.field.number') }}</th>
            <th>{{ __('investments.field.name') }}</th>
            <th>{{ __('investments.returns.field.current') }}</th>
            <th class="num">{{ __('investments.returns.field.expected_to_date') }}</th>
            <th class="num">{{ __('investments.returns.field.received') }}</th>
            <th class="num">{{ __('investments.returns.field.difference') }}</th>
            <th>{{ __('investments.returns.field.last_received') }}</th>
            <th>{{ __('investments.returns.field.next_due') }}</th>
            <th>{{ __('investments.field.status') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ Display::digits($row['investment']->investment_no) }}</td>
                <td>{{ $row['investment']->displayName() }}</td>
                <td>
                    @if ($row['current'])
                        @if ($row['current']->amount_poisha->isPositive()){{ Display::money($row['current']->amount_poisha) }} · @endif{{ $row['current']->frequency->getLabel() }}
                    @else
                        —
                    @endif
                </td>
                <td class="num">{{ Display::money($row['expected']) }}</td>
                <td class="num">{{ Display::money($row['received']) }}</td>
                <td class="num {{ $row['difference']->isNegative() ? 'rpt-status-bad' : '' }}">
                    {{ Display::money($row['difference']) }}
                    @if ($row['behind_periods'] > 0)
                        <br><span class="muted">{{ __('investments.returns.report.behind', ['count' => Display::digits((string) $row['behind_periods'])]) }}</span>
                    @endif
                </td>
                <td>{{ Display::date($row['last_received_on']) }}</td>
                <td>{{ $row['next_due'] ? Display::date($row['next_due']['due_on']).' · '.Display::money($row['next_due']['amount']) : '—' }}</td>
                <td>{{ $row['investment']->status->getLabel() }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">{{ __('investments.returns.report.empty') }}</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3">{{ __('journal.line.total') }}</td>
            <td class="num">{{ Display::money($expected) }}</td>
            <td class="num">{{ Display::money($received) }}</td>
            <td class="num">{{ Display::money($difference) }}</td>
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>
<p class="muted" style="margin-top: 8px;">{{ __('investments.returns.report.note') }}</p>
