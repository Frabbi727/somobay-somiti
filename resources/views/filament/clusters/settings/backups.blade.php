@php use App\Filament\Support\Display; @endphp

<x-filament-panels::page>
    @unless ($passwordSet)
        <div class="flex gap-3 rounded-xl bg-danger-50 p-4 text-sm text-danger-700 ring-1 ring-danger-200 dark:bg-danger-500/10 dark:text-danger-300 dark:ring-danger-500/30">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 shrink-0" />
            <div>
                <p class="font-semibold">{{ __('backups.no_password_heading') }}</p>
                <p class="mt-1">{{ __('backups.no_password_text') }}</p>
            </div>
        </div>
    @endunless

    {{-- At a glance --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backups.last_backup') }}</p>
                <x-filament::badge :color="$allHealthy ? 'success' : 'danger'" :icon="$allHealthy ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'">
                    {{ $allHealthy ? __('backups.ok') : __('backups.unhealthy') }}
                </x-filament::badge>
            </div>
            <p class="mt-2 text-xl font-semibold text-gray-950 dark:text-white">{{ $latest['when'] ?? __('backups.never') }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backups.next_backup') }}</p>
            <p class="mt-2 text-xl font-semibold text-gray-950 dark:text-white">{{ $next }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('backups.automatic') }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backups.kept') }}</p>
            <p class="mt-2 text-xl font-semibold text-gray-950 dark:text-white">{{ Display::digits(count($backups)) }} · {{ $totalSize }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('backups.encrypted') }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backups.check.last') }}</p>
                @if ($check !== null)
                    <x-filament::badge :color="$check['passed'] ? 'success' : 'danger'" :icon="$check['passed'] ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle'">
                        {{ $check['passed'] ? __('backups.check.passed') : __('backups.check.failed') }}
                    </x-filament::badge>
                @endif
            </div>
            <p class="mt-2 text-xl font-semibold text-gray-950 dark:text-white">{{ $checkLabel }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('backups.check.schedule') }}</p>
        </div>
    </div>

    {{-- Where they are kept --}}
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($health as $disk)
            <div class="flex items-start gap-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div @class([
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                    'bg-success-50 text-success-600 dark:bg-success-500/10 dark:text-success-400' => $disk['healthy'],
                    'bg-danger-50 text-danger-600 dark:bg-danger-500/10 dark:text-danger-400' => ! $disk['healthy'],
                ])>
                    <x-filament::icon :icon="$disk['disk'] === 'offsite' ? 'heroicon-o-cloud' : 'heroicon-o-server'" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-semibold text-gray-950 dark:text-white">{{ __('backups.disk.'.$disk['disk']) }}</p>
                        <x-filament::badge size="sm" :color="$disk['healthy'] ? 'success' : 'danger'">{{ $disk['healthy'] ? __('backups.ok') : __('backups.unhealthy') }}</x-filament::badge>
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('backups.disk_summary', ['newest' => $disk['newest_label'], 'count' => Display::digits($disk['count']), 'used' => $disk['used_label']]) }}
                    </p>
                    @foreach ($disk['problems'] as $problem)
                        <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $problem }}</p>
                    @endforeach
                </div>
            </div>
        @endforeach
        @unless (collect($health)->contains('disk', 'offsite'))
            <div class="flex items-start gap-4 rounded-xl border border-dashed border-gray-300 p-5 dark:border-white/15">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-warning-50 text-warning-600 dark:bg-warning-500/10 dark:text-warning-400">
                    <x-filament::icon icon="heroicon-o-cloud" class="h-5 w-5" />
                </div>
                <div>
                    <p class="font-semibold text-gray-950 dark:text-white">{{ __('backups.offsite_missing') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('backups.offsite_missing_text') }}</p>
                </div>
            </div>
        @endunless
    </div>

    {{-- Every backup --}}
    <x-filament::section :heading="__('backups.list')" :description="__('backups.retention')" compact>
        @if ($backups === [])
            <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('backups.none') }}</div>
        @else
            <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/5">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3 text-start font-semibold text-gray-950 sm:px-6 dark:text-white">{{ __('backups.when') }}</th>
                            <th class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ __('backups.where') }}</th>
                            <th class="px-4 py-3 text-end font-semibold text-gray-950 dark:text-white">{{ __('backups.size') }}</th>
                            <th class="px-4 py-3"><span class="sr-only">{{ __('backups.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @foreach ($backups as $backup)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5" wire:key="backup-{{ $backup['disk'] }}-{{ $backup['name'] }}">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-950 dark:text-white">{{ $backup['when'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $backup['name'] }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <x-filament::badge :color="$backup['disk'] === 'offsite' ? 'info' : 'gray'" :icon="$backup['disk'] === 'offsite' ? 'heroicon-m-cloud' : 'heroicon-m-server'">
                                        {{ __('backups.disk.'.$backup['disk']) }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-4 py-3 text-end tabular-nums text-gray-700 dark:text-gray-300">{{ $backup['size_label'] }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        {{ ($this->downloadAction)(['disk' => $backup['disk'], 'path' => $backup['path']]) }}
                                        {{ ($this->restoreAction)(['disk' => $backup['disk'], 'path' => $backup['path']]) }}
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
