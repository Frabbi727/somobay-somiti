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
    <htmlpagefooter name="footer">
        <table width="100%" style="font-size: 8pt; color: #71717a;">
            <tr>
                <td>{{ config('app.name') }} · {{ __('reports.generated_at', ['time' => \App\Filament\Support\Display::dateTime(now())]) }}</td>
                <td style="text-align: right;">{{ __('reports.page') }} {PAGENO}/{nbpg}</td>
            </tr>
        </table>
    </htmlpagefooter>
    <sethtmlpagefooter name="footer" value="on" />

    <h1>{{ config('app.name') }}</h1>
    <p class="sub">{{ $heading }}</p>

    @yield('content')
</body>
</html>
