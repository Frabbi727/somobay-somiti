<x-filament-panels::page>
    @unless ($passwordSet)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger" :heading="__('backups.no_password_heading')" :description="__('backups.no_password_text')" />
    @endunless

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($health as $disk)
            <x-filament::section
                :icon="$disk['healthy'] ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
                :icon-color="$disk['healthy'] ? 'success' : 'danger'"
                :heading="__('backups.disk.'.$disk['disk'])"
                :description="$disk['healthy'] ? __('backups.healthy') : __('backups.unhealthy')"
            >
                <dl class="grid grid-cols-3 gap-2 text-sm">
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('backups.newest') }}</dt><dd class="font-medium">{{ $disk['newest_label'] }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('backups.count') }}</dt><dd class="font-medium">{{ \App\Filament\Support\Display::digits($disk['count']) }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('backups.used') }}</dt><dd class="font-medium">{{ $disk['used_label'] }}</dd></div>
                </dl>
                @foreach ($disk['problems'] as $problem)
                    <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $problem }}</p>
                @endforeach
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section :heading="__('backups.list')" :description="__('backups.schedule')">
        @if ($backups === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backups.none') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pe-4 text-start font-medium text-gray-500">{{ __('backups.when') }}</th>
                            <th class="py-2 pe-4 text-start font-medium text-gray-500">{{ __('backups.where') }}</th>
                            <th class="py-2 pe-4 text-end font-medium text-gray-500">{{ __('backups.size') }}</th>
                            <th class="py-2 text-end font-medium text-gray-500"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($backups as $backup)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5" wire:key="backup-{{ $backup['disk'] }}-{{ $backup['name'] }}">
                                <td class="py-2 pe-4">{{ $backup['when'] }}<div class="text-xs text-gray-500">{{ $backup['name'] }}</div></td>
                                <td class="py-2 pe-4"><x-filament::badge :color="$backup['disk'] === 'offsite' ? 'info' : 'gray'">{{ __('backups.disk.'.$backup['disk']) }}</x-filament::badge></td>
                                <td class="py-2 pe-4 text-end">{{ $backup['size_label'] }}</td>
                                <td class="py-2 text-end">
                                    <div class="flex justify-end gap-1">
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
