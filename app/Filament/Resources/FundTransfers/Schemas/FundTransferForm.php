<?php

declare(strict_types=1);

namespace App\Filament\Resources\FundTransfers\Schemas;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Filament\Forms\Components\MoneyInput;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

final class FundTransferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('from_method')
                        ->label(__('transfers.field.from'))
                        ->options(PaymentMethod::class)
                        ->default(PaymentMethod::Cash->value)
                        ->required()
                        ->native(false),
                    Select::make('to_method')
                        ->label(__('transfers.field.to'))
                        ->options(PaymentMethod::class)
                        ->default(PaymentMethod::Bank->value)
                        ->different('from_method')
                        ->required()
                        ->native(false),
                    MoneyInput::make('amount')->label(__('transfers.field.amount'))->required(),
                    MoneyInput::make('charge')->label(__('transfers.field.charge'))->helperText(__('transfers.field.charge_help'))->default('0'),
                    DatePicker::make('transferred_on')
                        ->label(__('transfers.field.transferred_on'))
                        ->default(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())
                        ->maxDate(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())
                        ->native(false)
                        ->required(),
                    TextInput::make('reference')->label(__('transfers.field.reference'))->maxLength(60),
                    Textarea::make('notes')->label(__('transfers.field.notes'))->rows(2)->columnSpanFull(),
                    FileUpload::make('attachment_path')
                        ->label(__('transfers.field.attachment'))
                        ->disk('local')
                        ->directory('transfer-slips')
                        ->visibility('private')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize((int) config('somiti.max_proof_kb'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function summaryLabels(): array
    {
        return [
            'from' => __('transfers.field.from'),
            'to' => __('transfers.field.to'),
            'amount' => __('transfers.field.amount'),
            'charge' => __('transfers.field.charge'),
            'transferred_on' => __('transfers.field.transferred_on'),
            'reference' => __('transfers.field.reference'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function summaryValues(array $data): array
    {
        $method = fn (string $key): ?PaymentMethod => ($data[$key] ?? null) instanceof PaymentMethod ? $data[$key] : PaymentMethod::tryFrom((string) ($data[$key] ?? ''));
        $taka = fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? '৳ '.$data[$key] : null;

        return [
            'from' => $method('from_method'),
            'to' => $method('to_method'),
            'amount' => $taka('amount'),
            'charge' => $taka('charge'),
            'transferred_on' => $data['transferred_on'] ?? null,
            'reference' => $data['reference'] ?? null,
        ];
    }
}
