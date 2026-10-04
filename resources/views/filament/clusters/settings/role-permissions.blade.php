<x-filament-panels::page>
    @unless ($canEdit)
        <x-filament::section icon="heroicon-o-eye" :heading="__('permissions.read_only')" />
    @endunless

    <x-filament::section :description="__('permissions.locked_rules')" icon="heroicon-o-lock-closed" :heading="__('permissions.locked_heading')" collapsible collapsed />

    @foreach ($groups as $group => $permissions)
        <x-filament::section :heading="__('permissions.group.'.$group)" collapsible>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pe-4 text-start font-medium text-gray-500 dark:text-gray-400">{{ __('permissions.permission_column') }}</th>
                            @foreach ($roles as $role)
                                <th class="px-2 py-2 text-center font-medium text-gray-500 dark:text-gray-400">{{ $role->getLabel() }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($permissions as $permission)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5" wire:key="perm-{{ $permission->name }}">
                                <td class="py-2 pe-4">{{ $permission->getLabel() }}</td>
                                @foreach ($roles as $role)
                                    <td class="px-2 py-2 text-center">
                                        @if ($permission->isLockedFor($role))
                                            <span title="{{ __('permissions.auditor_locked') }}" class="text-gray-400">—</span>
                                        @else
                                            <input
                                                type="checkbox"
                                                class="fi-checkbox-input"
                                                aria-label="{{ $role->getLabel() }}: {{ $permission->getLabel() }}"
                                                wire:model="grid.{{ $permission->name }}.{{ $role->value }}"
                                                @disabled(! $canEdit)
                                            >
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
