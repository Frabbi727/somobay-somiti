@php
    use App\Filament\Support\Display;
    /** @var \App\Domain\Accounting\Reports\LedgerReport $report */
@endphp

<table class="rpt">
    <thead>
        <tr>
            <th>{{ __('reports.ledger.date') }}</th>
            <th>{{ __('reports.ledger.voucher') }}</th>
            <th>{{ __('reports.ledger.narration') }}</th>
            @if ($report->memberId === null && $report->account->requires_member)
                <th>{{ __('reports.ledger.member') }}</th>
            @endif
            <th class="num">{{ __('reports.ledger.debit') }}</th>
            <th class="num">{{ __('reports.ledger.credit') }}</th>
            <th class="num">{{ __('reports.ledger.balance') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ Display::date($report->from) }}</td>
            <td></td>
            <td class="muted">{{ __('reports.ledger.opening') }}</td>
            @if ($report->memberId === null && $report->account->requires_member)
                <td></td>
            @endif
            <td></td>
            <td></td>
            <td class="num">{{ Display::balance($report->opening) }}</td>
        </tr>
        @forelse ($report->rows as $row)
            <tr>
                <td>{{ Display::date($row->date) }}</td>
                <td>{{ Display::digits($row->voucherNo) }}</td>
                <td>{{ $row->narration }}@if ($row->memo) <span class="muted">— {{ $row->memo }}</span>@endif</td>
                @if ($report->memberId === null && $report->account->requires_member)
                    <td>{{ $row->memberId === null ? '—' : Display::digits($row->memberId) }}</td>
                @endif
                <td class="num">{{ $row->debit->isZero() ? '' : Display::money($row->debit) }}</td>
                <td class="num">{{ $row->credit->isZero() ? '' : Display::money($row->credit) }}</td>
                <td class="num">{{ Display::balance($row->balance) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="muted">{{ __('reports.ledger.empty') }}</td>
            </tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="{{ $report->memberId === null && $report->account->requires_member ? 4 : 3 }}">{{ __('reports.ledger.closing') }}</td>
            <td class="num">{{ Display::money($report->totalDebit()) }}</td>
            <td class="num">{{ Display::money($report->totalCredit()) }}</td>
            <td class="num">{{ Display::balance($report->closing()) }}</td>
        </tr>
    </tfoot>
</table>
