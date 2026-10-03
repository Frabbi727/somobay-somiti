{{-- Rows of [account, amount] under a heading, with a total line. --}}
@php use App\Filament\Support\Display; @endphp
<tr><th colspan="3" style="padding-top: 8px;">{{ $heading }}</th></tr>
@foreach ($rows as $row)
    <tr>
        <td>{{ Display::digits($row['account']->code) }}</td>
        <td>{{ Display::isBangla() ? $row['account']->name_bn : $row['account']->name_en }}</td>
        <td class="num">{{ Display::money($row['amount']) }}</td>
    </tr>
@endforeach
@isset($total)
    <tr><td></td><td><strong>{{ $totalLabel }}</strong></td><td class="num"><strong>{{ Display::money($total) }}</strong></td></tr>
@endisset
