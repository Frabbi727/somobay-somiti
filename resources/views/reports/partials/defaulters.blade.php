@php use App\Filament\Support\Display; @endphp
<table class="rpt">
    <thead>
        <tr>
            <th>{{ __('payments.field.member') }}</th>
            <th>{{ __('members.member.mobile') }}</th>
            <th class="num">{{ __('reports.defaulters.months') }}</th>
            <th>{{ __('reports.defaulters.oldest') }}</th>
            <th class="num">{{ __('dues.outstanding') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ Display::digits($row['member']->member_no) }} · {{ Display::isBangla() ? $row['member']->name_bn : $row['member']->name_en }}</td>
                <td>{{ Display::digits($row['member']->mobile) }}</td>
                <td class="num">{{ Display::digits($row['months']) }}</td>
                <td>{{ Display::yearMonth($row['oldest']) }}</td>
                <td class="num">{{ Display::money($row['outstanding']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="rpt-status-ok">{{ __('reports.defaulters.none') }}</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr><td colspan="4">{{ __('journal.line.total') }}</td><td class="num">{{ Display::money($total) }}</td></tr>
    </tfoot>
</table>
