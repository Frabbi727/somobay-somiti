@php
    use App\Filament\Support\Display;

    /** @var list<\App\Domain\Reporting\Data\SubmissionRow> $rows */
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('dashboard.my_submissions')" :description="__('dashboard.my_submissions_help')" icon="heroicon-o-inbox-arrow-down">
        @if ($rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.my_submissions_empty') }}</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rows as $row)
                    <li class="py-3">
                        <a href="{{ $row->url }}" class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between sm:gap-4 hover:opacity-80">
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $row->type }}</span>
                                    <span class="font-medium truncate">{{ $row->reference }}</span>
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ Display::dateTime($row->enteredAt) }}
                                    @if ($row->waitingFor !== null)
                                        · {{ __('dashboard.waiting_for', ['names' => $row->waitingFor]) }}
                                    @endif
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                @if ($row->amount !== '')
                                    <span class="font-semibold tabular-nums">{{ $row->amount }}</span>
                                @endif
                                <x-filament::badge :color="$row->statusColor">{{ $row->statusLabel }}</x-filament::badge>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
