<div class="rounded-2xl bg-white p-6 shadow-sm dark:bg-zinc-900">
    <h2 class="mb-4 text-lg font-semibold">{{ __('portal.login.heading') }}</h2>

    @if ($error)
        <p class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300" role="alert">{{ $error }}</p>
    @endif

    <label class="mb-1 block text-sm font-medium" for="mobile">{{ __('portal.login.mobile') }}</label>
    <input id="mobile" type="tel" inputmode="tel" autocomplete="tel" wire:model="mobile" placeholder="01XXXXXXXXX"
           class="mb-4 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">

    @if ($usePassword)
        <form wire:submit="loginWithPassword">
            <label class="mb-1 block text-sm font-medium" for="password">{{ __('portal.login.password') }}</label>
            <input id="password" type="password" autocomplete="current-password" wire:model="password"
                   class="mb-4 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
            <button class="w-full rounded-lg bg-emerald-600 py-2 font-medium text-white" wire:loading.attr="disabled">{{ __('portal.login.verify') }}</button>
        </form>
        <button type="button" wire:click="$set('usePassword', false)" class="mt-3 text-sm text-emerald-700 underline dark:text-emerald-400">{{ __('portal.login.use_code') }}</button>
    @elseif (! $codeSent)
        <button type="button" wire:click="sendCode" wire:loading.attr="disabled" class="w-full rounded-lg bg-emerald-600 py-2 font-medium text-white">{{ __('portal.login.send_code') }}</button>
        <button type="button" wire:click="$set('usePassword', true)" class="mt-3 text-sm text-emerald-700 underline dark:text-emerald-400">{{ __('portal.login.use_password') }}</button>
    @else
        <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-400">{{ __('portal.login.code_sent') }}</p>
        <form wire:submit="verifyCode">
            <label class="mb-1 block text-sm font-medium" for="code">{{ __('portal.login.code') }}</label>
            <input id="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" wire:model="code"
                   class="mb-4 w-full rounded-lg border border-zinc-300 px-3 py-2 text-center text-2xl tracking-widest dark:border-zinc-700 dark:bg-zinc-800">
            <button class="w-full rounded-lg bg-emerald-600 py-2 font-medium text-white" wire:loading.attr="disabled">{{ __('portal.login.verify') }}</button>
        </form>
        <button type="button" wire:click="sendCode" class="mt-3 text-sm text-emerald-700 underline dark:text-emerald-400">{{ __('portal.login.resend') }}</button>
    @endif
</div>
