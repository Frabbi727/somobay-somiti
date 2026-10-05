@php
    /** @var \App\Domain\Settings\Models\SomitiProfile $profile */
    $name = $profile->displayName($locale);
    $address = $profile->displayAddress($locale);
    $email = $profile->email ?: config('somiti.privacy_contact_email');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('privacy.title') }} · {{ $name }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f7f8f7; --card: #fff; --text: #1f2937; --muted: #6b7280; --accent: #047857; --line: #e5e7eb; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111827; --card: #1f2937; --text: #f3f4f6; --muted: #9ca3af; --accent: #34d399; --line: #374151; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.7 system-ui, -apple-system, "Segoe UI", "Noto Sans Bengali", sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
        header { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
        header img { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; }
        .brand { font-weight: 600; }
        .switch { margin-left: auto; color: var(--accent); text-decoration: none; font-size: 14px; white-space: nowrap; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
        h1 { margin: 0 0 4px; font-size: 26px; line-height: 1.3; }
        h2 { margin: 28px 0 8px; font-size: 18px; color: var(--accent); }
        .updated { color: var(--muted); font-size: 14px; margin: 0 0 16px; }
        ul { margin: 0; padding-left: 20px; }
        li + li { margin-top: 6px; }
        dl { margin: 8px 0 0; display: grid; grid-template-columns: max-content 1fr; gap: 4px 12px; }
        dt { color: var(--muted); }
        dd { margin: 0; overflow-wrap: anywhere; }
        a { color: var(--accent); }
    </style>
</head>
<body>
<main>
    <header>
        @if ($logo = $profile->logoDataUri())
            <img src="{{ $logo }}" alt="">
        @endif
        <span class="brand">{{ $name }}</span>
        <a class="switch" href="{{ route('privacy', $otherLocale === 'en' ? ['lang' => 'en'] : []) }}" lang="{{ $otherLocale }}">{{ __('privacy.switch') }}</a>
    </header>

    <article class="card">
        <h1>{{ __('privacy.title') }}</h1>
        <p class="updated">{{ __('privacy.updated') }}</p>
        <p>{{ __('privacy.intro', ['name' => $name]) }}</p>

        @foreach (__('privacy.sections') as $section)
            <h2>{{ $section['heading'] }}</h2>
            <ul>
                @foreach ($section['items'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        @endforeach

        <h2>{{ __('privacy.contact.heading') }}</h2>
        <p>{{ __('privacy.contact.body', ['name' => $name]) }}</p>
        <dl>
            @if ($address)
                <dt>{{ __('privacy.contact.address') }}</dt><dd>{{ $address }}</dd>
            @endif
            @if ($profile->phone)
                <dt>{{ __('privacy.contact.phone') }}</dt><dd><a href="tel:{{ $profile->phone }}">{{ $profile->phone }}</a></dd>
            @endif
            @if ($email)
                <dt>{{ __('privacy.contact.email') }}</dt><dd><a href="mailto:{{ $email }}">{{ $email }}</a></dd>
            @endif
        </dl>
    </article>
</main>
</body>
</html>
