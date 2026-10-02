<?php

declare(strict_types=1);

namespace App\Filament\Resources\JournalDrafts\Schemas;

use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\JournalDraft;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Support\AccountOptions;
use App\Filament\Support\Display;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class JournalDraftForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('voucher_type')
                            ->label(__('journal.voucher.type'))
                            ->options(array_combine(
                                array_map(fn (VoucherType $type): string => $type->value, VoucherType::manual()),
                                array_map(fn (VoucherType $type): string => $type->getLabel(), VoucherType::manual()),
                            ))
                            ->default(VoucherType::Journal->value)
                            ->required()
                            ->native(false),
                        DatePicker::make('entry_date')
                            ->label(__('journal.voucher.entry_date'))
                            ->default(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())
                            ->required()
                            ->native(false),
                        Textarea::make('narration')
                            ->label(__('journal.voucher.narration'))
                            ->required()
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('journal.voucher.lines'))
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->addActionLabel(__('journal.draft.add_line'))
                            ->defaultItems(2)
                            ->minItems(2)
                            ->columns(12)
                            ->schema([
                                Select::make('account_id')
                                    ->label(__('journal.line.account'))
                                    ->options(fn (): array => AccountOptions::active())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->columnSpan(['default' => 12, 'md' => 4]),
                                TextInput::make('member_id')
                                    ->label(__('journal.line.member'))
                                    ->integer()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => AccountOptions::requiresMember($get('account_id')))
                                    ->required(fn (Get $get): bool => AccountOptions::requiresMember($get('account_id')))
                                    ->columnSpan(['default' => 12, 'md' => 2]),
                                MoneyInput::make('debit')
                                    ->label(__('journal.line.debit'))
                                    ->live(onBlur: true)
                                    ->columnSpan(['default' => 6, 'md' => 2]),
                                MoneyInput::make('credit')
                                    ->label(__('journal.line.credit'))
                                    ->live(onBlur: true)
                                    ->columnSpan(['default' => 6, 'md' => 2]),
                                TextInput::make('memo')
                                    ->label(__('journal.line.memo'))
                                    ->maxLength(255)
                                    ->columnSpan(['default' => 12, 'md' => 2]),
                            ]),
                        TextEntry::make('totals')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => self::totalsText($get('lines')))
                            ->color(fn (Get $get): string => self::isBalanced($get('lines')) ? 'success' : 'danger')
                            ->weight('bold'),
                    ]),
            ]);
    }

    /**
     * Turns stored poisha lines into form state (MoneyInput formats int poisha as taka text).
     *
     * @return array<string, mixed>
     */
    public static function fillFrom(JournalDraft $draft): array
    {
        return [
            'voucher_type' => $draft->voucher_type->value,
            'entry_date' => $draft->entry_date->toDateString(),
            'narration' => $draft->narration,
            'lines' => array_map(fn (array $line): array => [
                'account_id' => $line['account_id'],
                'member_id' => $line['member_id'],
                'debit' => $line['debit_poisha'] === 0 ? null : $line['debit_poisha'],
                'credit' => $line['credit_poisha'] === 0 ? null : $line['credit_poisha'],
                'memo' => $line['memo'],
            ], $draft->lines),
        ];
    }

    /**
     * Server-side running totals; the browser never adds money up (§3 rule 5).
     *
     * @return array{0: Money, 1: Money}
     */
    private static function totals(mixed $lines): array
    {
        $debit = Money::zero();
        $credit = Money::zero();

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line)) {
                continue;
            }

            $debit = $debit->plus(self::parse($line['debit'] ?? null));
            $credit = $credit->plus(self::parse($line['credit'] ?? null));
        }

        return [$debit, $credit];
    }

    private static function isBalanced(mixed $lines): bool
    {
        [$debit, $credit] = self::totals($lines);

        return $debit->isPositive() && $debit->equals($credit);
    }

    private static function totalsText(mixed $lines): string
    {
        [$debit, $credit] = self::totals($lines);

        $text = __('journal.draft.totals', ['debit' => Display::money($debit), 'credit' => Display::money($credit)]);

        return $text.' — '.(self::isBalanced($lines)
            ? __('journal.draft.balanced')
            : __('journal.draft.difference', ['difference' => Display::money($debit->minus($credit)->absolute())]));
    }

    private static function parse(mixed $value): Money
    {
        return match (true) {
            $value instanceof Money => $value,
            is_int($value) => Money::ofPoisha($value),
            is_string($value) => Money::tryOfTaka($value) ?? Money::zero(),
            default => Money::zero(),
        };
    }
}
