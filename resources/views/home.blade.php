<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-emerald-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
        <div class="mb-8 flex items-center justify-between">
            <h1 class="text-2xl font-semibold">{{ config('app.name') }}</h1>
            <form method="POST" action="{{ route('locale', app()->getLocale() === 'bn' ? 'en' : 'bn') }}">
                @csrf
                <button class="rounded-full border border-zinc-300 px-3 py-1 text-sm dark:border-zinc-700">{{ __('portal.nav.language') }}</button>
            </form>
        </div>

        <p class="mb-6 text-zinc-600 dark:text-zinc-400">{{ __('home.intro') }}</p>

        <div class="space-y-4">
            <a href="{{ route('filament.member.auth.login') }}" class="block rounded-2xl bg-emerald-600 p-5 text-white shadow-sm transition hover:bg-emerald-700">
                <span class="block text-lg font-semibold">{{ __('home.member.title') }}</span>
                <span class="mt-1 block text-sm text-emerald-50">{{ __('home.member.text') }}</span>
            </a>

            <a href="{{ url('/admin') }}" class="block rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-emerald-400 dark:border-zinc-800 dark:bg-zinc-900">
                <span class="block text-lg font-semibold">{{ __('home.staff.title') }}</span>
                <span class="mt-1 block text-sm text-zinc-600 dark:text-zinc-400">{{ __('home.staff.text') }}</span>
            </a>
        </div>
    </main>
</body>
</html>
