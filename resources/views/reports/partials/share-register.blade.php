@php use App\Filament\Support\Display; @endphp
<table class="rpt">
    <thead>
        <tr>
            <th>{{ __('members.member.member_no') }}</th>
            <th>{{ __('members.member.name') }}</th>
            <th>{{ __('members.member.joined_on') }}</th>
            <th>{{ __('members.member.status') }}</th>
            <th class="num">{{ __('members.member.shares') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ Display::digits($row['member']->member_no) }}</td>
                <td>{{ $row['member']->name_bn }} · {{ $row['member']->name_en }}</td>
                <td>{{ Display::date($row['member']->joined_on) }}</td>
                <td>{{ $row['member']->status->getLabel() }}</td>
                <td class="num">{{ Display::digits($row['shares']) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr><td colspan="4">{{ __('journal.line.total') }}</td><td class="num">{{ Display::digits($total) }}</td></tr>
    </tfoot>
</table>
