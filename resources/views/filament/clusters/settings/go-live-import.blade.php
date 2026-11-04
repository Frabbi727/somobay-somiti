@php use App\Filament\Support\Display; @endphp

<x-filament-panels::page>
    @if ($import === null)
        <div class="rounded-xl bg-white p-6 text-sm text-gray-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
            <p class="font-semibold text-gray-950 dark:text-white">{{ __('golive.steps.heading') }}</p>
            <ol class="mt-3 list-decimal space-y-1 ps-5">
                <li>{{ __('golive.steps.download') }}</li>
                <li>{{ __('golive.steps.fill') }}</li>
                <li>{{ __('golive.steps.upload') }}</li>
                <li>{{ __('golive.steps.post') }}</li>
            </ol>
        </div>
    @else
        {{-- Status --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('golive.field.status') }}</p>
                <div class="mt-2">
                    <x-filament::badge :color="$import->status->getColor()" :icon="$import->status->getIcon()">
                        {{ $import->status->getLabel() }}
                    </x-filament::badge>
                </div>
            </div>
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('golive.go_live_month') }}</p>
                <p class="mt-2 text-xl font-semibold text-gray-950 dark:text-white">{{ Display::yearMonth($import->go_live_month) }}</p>
            </div>
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('golive.file') }}</p>
                <p class="mt-2 truncate text-base font-semibold text-gray-950 dark:text-white">{{ $import->file_name }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ Display::dateTime($import->updated_at) }}</p>
            </div>
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('golive.field.problems') }}</p>
                <p @class([
                    'mt-2 text-xl font-semibold',
                    'text-danger-600 dark:text-danger-400' => $problems !== [],
                    'text-success-600 dark:text-success-400' => $problems === [],
                ])>{{ Display::digits(count($problems)) }}</p>
            </div>
        </div>

        @if ($voucher !== null)
            <div class="flex gap-3 rounded-xl bg-success-50 p-4 text-sm text-success-700 ring-1 ring-success-200 dark:bg-success-500/10 dark:text-success-300 dark:ring-success-500/30">
                <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5 shrink-0" />
                <div>
                    <p class="font-semibold">{{ __('golive.posted_heading') }}</p>
                    <p class="mt-1">
                        {{ __('golive.posted_text', ['date' => Display::dateTime($import->posted_at)]) }}
                        <a href="{{ $voucherUrl }}" class="font-semibold underline">{{ Display::digits($voucher->voucher_no) }}</a>
                    </p>
                    <p class="mt-1">{{ __('golive.posted_next') }}</p>
                </div>
            </div>
        @endif

        @if ($problems !== [])
            {{-- Problems --}}
            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="border-b border-gray-200 px-5 py-3 dark:border-white/10">
                    <p class="font-semibold text-gray-950 dark:text-white">{{ __('golive.errors_heading') }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('golive.errors_help') }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-start text-gray-600 dark:bg-white/5 dark:text-gray-300">
                            <tr>
                                <th class="px-5 py-2 text-start font-medium">{{ __('golive.field.sheet') }}</th>
                                <th class="px-5 py-2 text-start font-medium">{{ __('golive.field.row') }}</th>
                                <th class="px-5 py-2 text-start font-medium">{{ __('golive.field.column') }}</th>
                                <th class="px-5 py-2 text-start font-medium">{{ __('golive.field.problem') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($problems as $error)
                                <tr>
                                    <td class="px-5 py-2 text-gray-950 dark:text-white">{{ $error['sheet'] }}</td>
                                    <td class="px-5 py-2 text-gray-950 dark:text-white">{{ $error['row'] }}</td>
                                    <td class="px-5 py-2 text-gray-950 dark:text-white">{{ $error['column'] }}</td>
                                    <td class="px-5 py-2 text-gray-700 dark:text-gray-300">{{ $error['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            {{-- Summary --}}
            <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
                @foreach (['members', 'shares', 'savings', 'advance', 'arrears', 'investments'] as $key)
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('golive.summary.'.$key) }}</p>
                        <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $summary[$key] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Opening trial balance --}}
            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="border-b border-gray-200 px-5 py-3 dark:border-white/10">
                    <p class="font-semibold text-gray-950 dark:text-white">{{ __('golive.tb_heading') }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('golive.tb_help') }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600 dark:bg-white/5 dark:text-gray-300">
                            <tr>
                                <th class="px-5 py-2 text-start font-medium">{{ __('golive.field.account') }}</th>
                                <th class="px-5 py-2 text-end font-medium">{{ __('golive.field.debit') }}</th>
                                <th class="px-5 py-2 text-end font-medium">{{ __('golive.field.credit') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($lines as $line)
                                <tr>
                                    <td class="px-5 py-2 text-gray-950 dark:text-white">{{ Display::digits($line['code']) }}</td>
                                    <td class="px-5 py-2 text-end tabular-nums text-gray-950 dark:text-white">{{ $line['debit']->isZero() ? '' : Display::money($line['debit']) }}</td>
                                    <td class="px-5 py-2 text-end tabular-nums text-gray-950 dark:text-white">{{ $line['credit']->isZero() ? '' : Display::money($line['credit']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50 font-semibold text-gray-950 dark:bg-white/5 dark:text-white">
                            <tr>
                                <td class="px-5 py-2">{{ __('golive.field.total') }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ Display::money($totalDebit) }}</td>
                                <td class="px-5 py-2 text-end tabular-nums">{{ Display::money($totalCredit) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
