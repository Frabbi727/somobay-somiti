@php
    use App\Filament\Support\Display;
    /** @var list<\App\Domain\Accounting\Reports\ControlCheck> $checks */
@endphp

<table class="rpt">
    <thead>
        <tr>
            <th>{{ __('reports.reconciliation.account') }}</th>
            <th>{{ __('reports.reconciliation.source') }}</th>
            <th class="num">{{ __('reports.reconciliation.general_ledger') }}</th>
            <th class="num">{{ __('reports.reconciliation.subledger') }}</th>
            <th class="num">{{ __('reports.reconciliation.difference') }}</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($checks as $check)
            <tr>
                <td>{{ Display::digits($check->account->code) }} · {{ Display::isBangla() ? $check->account->name_bn : $check->account->name_en }}</td>
                <td>{{ $check->source }}</td>
                <td class="num">{{ Display::balance($check->generalLedger) }}</td>
                <td class="num">{{ Display::balance($check->subledger) }}</td>
                <td class="num">{{ Display::money($check->difference()) }}</td>
                <td class="{{ $check->isReconciled() ? 'rpt-status-ok' : 'rpt-status-bad' }}">
                    {{ $check->isReconciled() ? __('reports.reconciliation.ok') : __('reports.reconciliation.mismatch') }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
