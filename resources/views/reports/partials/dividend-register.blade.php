@php use App\Filament\Support\Display; @endphp
<table class="rpt">
    <thead>
        <tr>
            <th>{{ __('members.member.member_no') }}</th>
            <th>{{ __('members.member.name') }}</th>
            <th class="num">{{ __('year_end.field.share_months') }}</th>
            <th class="num">{{ __('year_end.field.dividend') }}</th>
            <th>{{ __('year_end.field.status') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>{{ Display::digits($line->member->member_no) }}</td>
                <td>{{ $line->member->name_bn }} · {{ $line->member->name_en }}</td>
                <td class="num">{{ Display::digits($line->share_months) }}</td>
                <td class="num">{{ Display::money($line->amount_poisha) }}</td>
                <td>{{ $line->status->getLabel() }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="2">{{ __('journal.line.total') }}</td>
            <td class="num">{{ Display::digits($share_months) }}</td>
            <td class="num">{{ Display::money($total) }}</td>
            <td></td>
        </tr>
    </tfoot>
</table>
