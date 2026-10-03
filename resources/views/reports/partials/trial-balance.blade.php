@php
    use App\Filament\Support\Display;
    /** @var \App\Domain\Accounting\Reports\TrialBalanceReport $report */
@endphp

@if (count($report->balanceRows()) === 0)
    <p class="muted">{{ __('reports.trial_balance.empty') }}</p>
@else
    <table class="rpt">
        <thead>
            <tr>
                <th>{{ __('reports.trial_balance.code') }}</th>
                <th>{{ __('reports.trial_balance.account') }}</th>
                <th>{{ __('reports.trial_balance.type') }}</th>
                <th class="num">{{ __('reports.trial_balance.debit') }}</th>
                <th class="num">{{ __('reports.trial_balance.credit') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report->balanceRows() as $row)
                <tr>
                    <td>{{ Display::digits($row->account->code) }}</td>
                    <td>{{ Display::isBangla() ? $row->account->name_bn : $row->account->name_en }}</td>
                    <td>{{ $row->account->type->getLabel() }}</td>
                    <td class="num">{{ $row->debitBalance()->isZero() ? '' : Display::money($row->debitBalance()) }}</td>
                    <td class="num">{{ $row->creditBalance()->isZero() ? '' : Display::money($row->creditBalance()) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">{{ __('reports.trial_balance.total') }}</td>
                <td class="num">{{ Display::money($report->totalDebit()) }}</td>
                <td class="num">{{ Display::money($report->totalCredit()) }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="{{ $report->isBalanced() ? 'rpt-status-ok' : 'rpt-status-bad' }}">
        {{ $report->isBalanced()
            ? __('reports.trial_balance.balanced')
            : __('reports.trial_balance.out_of_balance', ['difference' => Display::money($report->difference()->absolute())]) }}
    </p>
@endif
