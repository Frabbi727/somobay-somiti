<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" x-data x-bind:class="{ 'dark': localStorage.getItem('portal-theme') === 'dark' }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('portal.title') }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-emerald-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-xl font-semibold">{{ config('app.name') }}</h1>
            <form method="POST" action="{{ route('portal.locale', app()->getLocale() === 'bn' ? 'en' : 'bn') }}">
                @csrf
                <button class="rounded-full border border-zinc-300 px-3 py-1 text-sm dark:border-zinc-700">{{ __('portal.nav.language') }}</button>
            </form>
        </div>
        {{ $slot }}
    </main>
</body>
</html>
