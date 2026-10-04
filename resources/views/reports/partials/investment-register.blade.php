@php use App\Filament\Support\Display; @endphp
<table class="rpt">
    <thead>
        <tr>
            <th>{{ __('investments.field.number') }}</th>
            <th>{{ __('investments.field.type') }}</th>
            <th>{{ __('investments.field.institution') }}</th>
            <th>{{ __('investments.field.invested_on') }}</th>
            <th>{{ __('investments.field.matures_on') }}</th>
            <th class="num">{{ __('investments.field.principal') }}</th>
            <th class="num">{{ __('investments.field.book_value') }}</th>
            <th class="num">{{ __('investments.report.income') }}</th>
            <th>{{ __('investments.field.status') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ Display::digits($row['investment']->investment_no) }}</td>
                <td>{{ $row['investment']->type->getLabel() }}</td>
                <td>{{ $row['investment']->institution }}@if ($row['investment']->instrument_no) · {{ $row['investment']->instrument_no }}@endif</td>
                <td>{{ Display::date($row['investment']->invested_on) }}</td>
                <td>{{ $row['investment']->matures_on ? Display::date($row['investment']->matures_on) : '—' }}</td>
                <td class="num">{{ Display::money($row['investment']->principal_poisha) }}</td>
                <td class="num">{{ Display::money($row['book']) }}</td>
                <td class="num">{{ Display::money($row['income']) }}</td>
                <td>{{ $row['investment']->status->getLabel() }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">{{ __('investments.report.empty') }}</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6">{{ __('journal.line.total') }}</td>
            <td class="num">{{ Display::money($total) }}</td>
            <td class="num">{{ Display::money($income) }}</td>
            <td></td>
        </tr>
    </tfoot>
</table>

<table class="rpt" style="margin-top: 12px;">
    <thead>
        <tr>
            <th>{{ __('investments.field.type') }}</th>
            <th class="num">{{ __('investments.report.register_total') }}</th>
            <th class="num">{{ __('investments.report.ledger_total') }}</th>
            <th>{{ __('investments.report.tie') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($groups as $group)
            <tr>
                <td>{{ $group['type']->getLabel() }} ({{ Display::digits($group['type']->accountCode()) }})</td>
                <td class="num">{{ Display::money($group['book']) }}</td>
                <td class="num">{{ Display::money($group['ledger']) }}</td>
                <td>{{ $group['book']->equals($group['ledger']) ? '✓' : __('investments.report.mismatch') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
