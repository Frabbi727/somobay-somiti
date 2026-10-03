<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Schemas;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
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

final class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('account_id')
                        ->label(__('expenses.field.category'))
                        ->options(fn (): array => self::categories())
                        ->required()
                        ->searchable()
                        ->native(false),
                    Select::make('paid_from')
                        ->label(__('expenses.field.paid_from'))
                        ->options(PaymentMethod::class)
                        ->default(PaymentMethod::Cash->value)
                        ->required()
                        ->native(false),
                    MoneyInput::make('amount')
                        ->label(__('expenses.field.amount'))
                        ->required(),
                    DatePicker::make('spent_on')
                        ->label(__('expenses.field.spent_on'))
                        ->default(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())
                        ->maxDate(fn (): string => CarbonImmutable::now(YearMonth::TIMEZONE)->toDateString())
                        ->native(false)
                        ->required(),
                    TextInput::make('payee')->label(__('expenses.field.payee'))->maxLength(255),
                    TextInput::make('reference')->label(__('expenses.field.reference'))->maxLength(60),
                    Textarea::make('description')
                        ->label(__('expenses.field.description'))
                        ->required()
                        ->minLength(3)
                        ->rows(2)
                        ->columnSpanFull(),
                    FileUpload::make('attachment_path')
                        ->label(__('expenses.field.attachment'))
                        ->disk('local')
                        ->directory('expense-bills')
                        ->visibility('private')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize((int) config('somiti.max_proof_kb'))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return Account::query()
            ->where('type', AccountType::Expense)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Account $account): array => [$account->id => $account->displayName()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function summaryLabels(): array
    {
        return [
            'category' => __('expenses.field.category'),
            'paid_from' => __('expenses.field.paid_from'),
            'amount' => __('expenses.field.amount'),
            'spent_on' => __('expenses.field.spent_on'),
            'payee' => __('expenses.field.payee'),
            'description' => __('expenses.field.description'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function summaryValues(array $data): array
    {
        $paidFrom = $data['paid_from'] ?? null;
        $amount = $data['amount'] ?? null;

        return [
            'category' => self::categories()[(int) ($data['account_id'] ?? 0)] ?? null,
            'paid_from' => $paidFrom instanceof PaymentMethod ? $paidFrom : PaymentMethod::tryFrom((string) $paidFrom),
            'amount' => is_string($amount) && $amount !== '' ? '৳ '.$amount : null,
            'spent_on' => $data['spent_on'] ?? null,
            'payee' => $data['payee'] ?? null,
            'description' => $data['description'] ?? null,
        ];
    }
}
