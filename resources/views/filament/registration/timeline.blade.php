@php
    use App\Domain\Members\Registration\Enums\TimelineState;
    use App\Filament\Support\Display;

    /** @var list<\App\Domain\Members\Registration\Data\TimelineStep> $steps */
    $steps = isset($getState) ? ($getState() ?? []) : ($steps ?? []);
@endphp

<ol class="space-y-4">
    @foreach ($steps as $step)
        <li class="flex gap-3">
            <x-filament::icon
                :icon="$step->state->getIcon()"
                @class([
                    'h-6 w-6 shrink-0',
                    'text-success-600 dark:text-success-400' => $step->state === TimelineState::Done,
                    'text-warning-600 dark:text-warning-400' => in_array($step->state, [TimelineState::Pending, TimelineState::Returned], true),
                    'text-danger-600 dark:text-danger-400' => $step->state === TimelineState::Rejected,
                    'text-gray-400' => $step->state === TimelineState::Waiting,
                ])
            />
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ $step->label }}</span>
                    <x-filament::badge :color="$step->state->getColor()">{{ $step->state->getLabel() }}</x-filament::badge>
                </div>
                @if ($step->actedAt)
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ Display::dateTime($step->actedAt) }}@if ($step->actorName) · {{ $step->actorName }}@endif
                    </div>
                @endif
                @if ($step->reason)
                    <div class="text-sm">{{ $step->reason }}</div>
                @endif
            </div>
        </li>
    @endforeach
</ol>
