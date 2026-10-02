@props(['rows' => [], 'showOld' => true])

@if (count($rows) === 0)
    <p style="text-align: center; opacity: .75;">{{ __('confirm.no_changes') }}</p>
@else
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: .875rem; text-align: start;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(127, 127, 127, .3);">
                    <th style="padding: .375rem .5rem; text-align: start; font-weight: 600;">{{ __('confirm.field') }}</th>
                    @if ($showOld)
                        <th style="padding: .375rem .5rem; text-align: start; font-weight: 600;">{{ __('confirm.old') }}</th>
                    @endif
                    <th style="padding: .375rem .5rem; text-align: start; font-weight: 600;">{{ $showOld ? __('confirm.new') : __('confirm.value') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, .15);">
                        <td style="padding: .375rem .5rem; font-weight: 500;">{{ $row['label'] }}</td>
                        @if ($showOld)
                            <td style="padding: .375rem .5rem; text-decoration: line-through; opacity: .7;">{{ $row['old'] }}</td>
                        @endif
                        <td style="padding: .375rem .5rem;">{{ $row['new'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
