@php
    $code = (string) ($code ?? 500);
    $reference = in_array($code, ['500', '503'], true) ? \App\Support\Errors\ErrorReference::current() : null;
    // Signed in but in the wrong place (e.g. a member on the staff panel): offer a way out, since
    // the start page would only lead back here.
    $user = rescue(fn () => auth()->user(), null, false);
    $isMember = $user instanceof \App\Models\User && ! $user->isStaff();
@endphp
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('errors.page.'.$code.'.title', [], 'bn') }} · {{ config('app.name') }}</title>
    <style>
        :root { --bg: #f8fafc; --card: #ffffff; --text: #0f172a; --muted: #475569; --line: #e2e8f0; --accent: #047857; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1220; --card: #111a2e; --text: #e2e8f0; --muted: #94a3b8; --line: #1e293b; --accent: #34d399; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background: var(--bg); color: var(--text); font-family: "Hind Siliguri", "Noto Sans Bengali", system-ui, sans-serif; line-height: 1.6; }
        main { width: 100%; max-width: 560px; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 28px; }
        .code { color: var(--muted); font-size: 14px; letter-spacing: .05em; }
        h1 { font-size: 22px; margin: 4px 0 8px; }
        p { margin: 0 0 12px; color: var(--muted); }
        hr { border: 0; border-top: 1px solid var(--line); margin: 20px 0; }
        .reference { font-family: ui-monospace, monospace; background: var(--bg); border: 1px dashed var(--line); border-radius: 8px; padding: 8px 12px; color: var(--text); }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
        a.button, button.button { display: inline-block; padding: 8px 14px; border-radius: 8px; text-decoration: none; border: 1px solid var(--line); color: var(--text); }
        a.button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    </style>
</head>
<body>
<main>
    <div class="code">{{ $code }}</div>

    @foreach (['bn', 'en'] as $locale)
        <h1 lang="{{ $locale }}">{{ __('errors.page.'.$code.'.title', [], $locale) }}</h1>
        <p lang="{{ $locale }}">{{ __('errors.page.'.$code.'.body', [], $locale) }}</p>
        @if ($reference !== null)
            <p class="reference" lang="{{ $locale }}">{{ __('errors.page.reference', ['reference' => $reference], $locale) }}</p>
        @endif
        @if ($loop->first)
            <hr>
        @endif
    @endforeach

    <div class="actions">
        @if ($isMember)
            <a class="button primary" href="{{ url('/portal') }}">{{ __('errors.page.portal', [], 'bn') }} · {{ __('errors.page.portal', [], 'en') }}</a>
        @else
            <a class="button primary" href="{{ url('/') }}">{{ __('errors.page.home', [], 'bn') }} · {{ __('errors.page.home', [], 'en') }}</a>
        @endif
        <a class="button" href="javascript:history.back()">{{ __('errors.page.back', [], 'bn') }} · {{ __('errors.page.back', [], 'en') }}</a>
        @if ($user !== null && rescue(fn () => csrf_token(), null, false) !== null)
            <form method="POST" action="{{ route($isMember ? 'filament.member.auth.logout' : 'filament.admin.auth.logout') }}" style="margin:0">
                @csrf
                <button type="submit" class="button" style="cursor:pointer;background:none;font:inherit">{{ __('errors.page.sign_out', [], 'bn') }} · {{ __('errors.page.sign_out', [], 'en') }}</button>
            </form>
        @endif
    </div>
</main>
</body>
</html>
