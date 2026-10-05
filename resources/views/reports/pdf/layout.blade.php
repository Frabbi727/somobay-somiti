<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    @include('reports.partials.styles')
    <style>
        body { font-size: 10pt; color: #18181b; }
        h1 { font-size: 15pt; margin: 0 0 2px; }
        h2 { font-size: 12pt; margin: 14px 0 6px; }
        .sub { color: #52525b; margin: 0 0 10px; }
    </style>
</head>
<body>
    @php($somiti = \App\Domain\Settings\Models\SomitiProfile::current())
    <htmlpagefooter name="footer">
        <table width="100%" style="font-size: 8pt; color: #71717a;">
            <tr>
                <td>{{ $somiti->displayName() }} · {{ __('reports.generated_at', ['time' => \App\Filament\Support\Display::dateTime(now())]) }}</td>
                <td style="text-align: right;">{{ __('reports.page') }} {PAGENO}/{nbpg}</td>
            </tr>
        </table>
    </htmlpagefooter>
    <sethtmlpagefooter name="footer" value="on" />

    <table width="100%" style="margin-bottom: 8px;">
        <tr>
            @if ($logo = $somiti->logoDataUri())
                <td style="width: 56px; vertical-align: middle;"><img src="{{ $logo }}" style="height: 48px;" alt=""></td>
            @endif
            <td style="vertical-align: middle;">
                <h1>{{ $somiti->displayName() }}</h1>
                <div class="sub" style="margin: 0;">
                    @if ($somiti->registration_no){{ __('somiti.registration', ['no' => \App\Filament\Support\Display::digits($somiti->registration_no)]) }}@endif
                    @if ($somiti->registration_no && $somiti->displayAddress()) · @endif
                    {{ $somiti->displayAddress() }}
                    @if ($somiti->phone) · {{ \App\Filament\Support\Display::digits($somiti->phone) }}@endif
                </div>
            </td>
        </tr>
    </table>
    <p class="sub">{{ $heading }}</p>

    @yield('content')
</body>
</html>
