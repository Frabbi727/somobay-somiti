@php
    $links = [
        'portal.dashboard' => __('portal.nav.dashboard'),
        'portal.dues' => __('portal.nav.dues'),
        'portal.receipts' => __('portal.nav.receipts'),
        'portal.submit' => __('portal.nav.submit'),
        'portal.profile' => __('portal.nav.profile'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}"
      x-data="{ dark: localStorage.getItem('portal-theme') === 'dark' }"
      x-init="$watch('dark', value => localStorage.setItem('portal-theme', value ? 'dark' : 'light'))"
      x-bind:class="{ 'dark': dark }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('portal.title') }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mx-auto flex max-w-4xl items-center justify-between gap-3 px-4 py-3">
            <a href="{{ route('portal.dashboard') }}" wire:navigate class="font-semibold text-emerald-700 dark:text-emerald-400">{{ config('app.name') }}</a>
            <div class="flex items-center gap-2 text-sm">
                <button type="button" x-on:click="dark = ! dark" class="rounded-full border border-zinc-300 px-3 py-1 dark:border-zinc-700" title="{{ __('portal.nav.theme') }}">
                    <span x-show="! dark">🌙</span><span x-show="dark" x-cloak>☀️</span>
                </button>
                <form method="POST" action="{{ route('portal.locale', app()->getLocale() === 'bn' ? 'en' : 'bn') }}">
                    @csrf
                    <button class="rounded-full border border-zinc-300 px-3 py-1 dark:border-zinc-700">{{ __('portal.nav.language') }}</button>
                </form>
                <form method="POST" action="{{ route('portal.logout') }}">
                    @csrf
                    <button class="rounded-full bg-zinc-900 px-3 py-1 text-white dark:bg-zinc-100 dark:text-zinc-900">{{ __('portal.nav.logout') }}</button>
                </form>
            </div>
        </div>
        <nav class="mx-auto flex max-w-4xl gap-1 overflow-x-auto px-4 pb-2 text-sm">
            @foreach ($links as $route => $label)
                <a href="{{ route($route) }}" wire:navigate
                   @class([
                       'whitespace-nowrap rounded-full px-3 py-1.5',
                       'bg-emerald-600 text-white' => request()->routeIs($route),
                       'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => ! request()->routeIs($route),
                   ])>{{ $label }}</a>
            @endforeach
        </nav>
    </header>

    <main class="mx-auto max-w-4xl px-4 py-6" wire:transition>
        {{ $slot }}
    </main>
</body>
</html>
