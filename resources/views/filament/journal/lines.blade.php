@php
    /** @var \App\Domain\Accounting\Models\JournalEntry $record */
    $record = $getRecord();
    $lines = $record->lines()->with('account')->get();
    $debit = \App\Support\Money\Money::sum($lines->map(fn ($line) => $line->debit_poisha));
    $credit = \App\Support\Money\Money::sum($lines->map(fn ($line) => $line->credit_poisha));
    $cell = 'padding: .5rem .625rem;';
    $num = $cell.' text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap;';
@endphp

<div style="overflow-x: auto;">
    <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
        <thead>
            <tr style="border-bottom: 1px solid rgba(127, 127, 127, .3); text-align: start;">
                <th style="{{ $cell }} text-align: start;">{{ \App\Filament\Support\Display::digits('#') }}</th>
                <th style="{{ $cell }} text-align: start;">{{ __('journal.line.account') }}</th>
                <th style="{{ $cell }} text-align: start;">{{ __('journal.line.member') }}</th>
                <th style="{{ $num }}">{{ __('journal.line.debit') }}</th>
                <th style="{{ $num }}">{{ __('journal.line.credit') }}</th>
                <th style="{{ $cell }} text-align: start;">{{ __('journal.line.memo') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr style="border-bottom: 1px solid rgba(127, 127, 127, .15);">
                    <td style="{{ $cell }}">{{ \App\Filament\Support\Display::digits($line->line_no) }}</td>
                    <td style="{{ $cell }}">{{ \App\Filament\Support\Display::digits($line->account->code) }} · {{ app()->getLocale() === 'bn' ? $line->account->name_bn : $line->account->name_en }}</td>
                    <td style="{{ $cell }}">{{ $line->member_id === null ? '—' : \App\Filament\Support\Display::digits($line->member_id) }}</td>
                    <td style="{{ $num }}">{{ $line->debit_poisha->isZero() ? '' : \App\Filament\Support\Display::money($line->debit_poisha) }}</td>
                    <td style="{{ $num }}">{{ $line->credit_poisha->isZero() ? '' : \App\Filament\Support\Display::money($line->credit_poisha) }}</td>
                    <td style="{{ $cell }}">{{ $line->memo ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="border-top: 2px solid rgba(127, 127, 127, .4); font-weight: 600;">
                <td style="{{ $cell }}" colspan="3">{{ __('journal.line.total') }}</td>
                <td style="{{ $num }}">{{ \App\Filament\Support\Display::money($debit) }}</td>
                <td style="{{ $num }}">{{ \App\Filament\Support\Display::money($credit) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
