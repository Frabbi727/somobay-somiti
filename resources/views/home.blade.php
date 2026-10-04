<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-b from-emerald-50 to-white text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-900 dark:text-zinc-100">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
        <div class="mb-8 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-600 text-white shadow-sm">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z"/></svg>
                </span>
                <h1 class="text-xl font-semibold leading-tight">{{ config('app.name') }}</h1>
            </div>
            <form method="POST" action="{{ route('locale', app()->getLocale() === 'bn' ? 'en' : 'bn') }}">
                @csrf
                <button class="rounded-full border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium shadow-sm hover:border-emerald-400 dark:border-zinc-700 dark:bg-zinc-900">{{ __('portal.nav.language') }}</button>
            </form>
        </div>

        <p class="mb-6 text-zinc-600 dark:text-zinc-400">{{ __('home.intro') }}</p>

        <div class="space-y-4">
            <a href="{{ route('filament.member.auth.login') }}" class="group flex items-center gap-4 rounded-2xl bg-emerald-600 p-5 text-white shadow-md transition hover:bg-emerald-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/15">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3"/></svg>
                </span>
                <span class="flex-1">
                    <span class="block text-lg font-semibold">{{ __('home.member.title') }}</span>
                    <span class="mt-1 block text-sm text-emerald-50">{{ __('home.member.text') }}</span>
                </span>
                <span class="text-2xl transition group-hover:translate-x-1" aria-hidden="true">→</span>
            </a>

            <a href="{{ route('filament.admin.auth.login') }}" class="group flex items-center gap-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-emerald-400 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200 dark:border-zinc-800 dark:bg-zinc-900">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg>
                </span>
                <span class="flex-1">
                    <span class="block text-lg font-semibold">{{ __('home.staff.title') }}</span>
                    <span class="mt-1 block text-sm text-zinc-600 dark:text-zinc-400">{{ __('home.staff.text') }}</span>
                </span>
                <span class="text-2xl text-zinc-400 transition group-hover:translate-x-1" aria-hidden="true">→</span>
            </a>
        </div>

        <p class="mt-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('home.help') }}</p>
    </main>
</body>
</html>
