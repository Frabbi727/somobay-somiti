@php
    /** @var \App\Domain\Audit\Models\AuditEntry $record */
    $record = $getRecord();
    $new = (array) ($record->attribute_changes?->get('attributes') ?? []);
    $old = (array) ($record->attribute_changes?->get('old') ?? []);
    $keys = array_values(array_unique([...array_keys($old), ...array_keys($new)]));
    $extra = collect($record->properties ?? [])->except(['ip', 'agent', 'via'])->all();
    $show = fn (mixed $value): string => $value === null ? '—' : (is_scalar($value) ? (is_bool($value) ? ($value ? '✓' : '✗') : (string) $value) : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
@endphp

@if ($keys === [] && $extra === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('audit.no_changes') }}</p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-start dark:border-white/10">
                    <th class="py-2 pe-4 text-start font-medium text-gray-500">{{ __('audit.field.field') }}</th>
                    <th class="py-2 pe-4 text-start font-medium text-gray-500">{{ __('audit.field.old') }}</th>
                    <th class="py-2 text-start font-medium text-gray-500">{{ __('audit.field.new') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($keys as $key)
                    <tr class="border-b border-gray-100 align-top last:border-0 dark:border-white/5">
                        <td class="py-2 pe-4 font-mono text-xs">{{ $key }}</td>
                        <td class="py-2 pe-4 whitespace-pre-wrap break-all text-danger-600 dark:text-danger-400">{{ array_key_exists($key, $old) ? $show($old[$key]) : '' }}</td>
                        <td class="py-2 whitespace-pre-wrap break-all text-success-600 dark:text-success-400">{{ array_key_exists($key, $new) ? $show($new[$key]) : '' }}</td>
                    </tr>
                @endforeach
                @foreach ($extra as $key => $value)
                    <tr class="border-b border-gray-100 align-top last:border-0 dark:border-white/5">
                        <td class="py-2 pe-4 font-mono text-xs">{{ $key }}</td>
                        <td class="py-2 whitespace-pre-wrap break-all" colspan="2">{{ $show($value) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
