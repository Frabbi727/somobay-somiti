<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Schemas;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        $hasLines = fn (?Account $record): bool => $record?->hasJournalLines() ?? false;

        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->label(__('accounting.account.code'))
                            ->helperText(__('accounting.account.code_help'))
                            ->required()
                            ->length(4)
                            ->regex('/^[1-9]\d{3}$/')
                            ->disabled($hasLines)
                            ->dehydrated(),
                        Select::make('type')
                            ->label(__('accounting.account.type'))
                            ->options(AccountType::class)
                            ->required()
                            ->native(false)
                            ->disabled($hasLines)
                            ->dehydrated(),
                        TextInput::make('name_bn')
                            ->label(__('accounting.account.name_bn'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name_en')
                            ->label(__('accounting.account.name_en'))
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_control')
                            ->label(__('accounting.account.is_control'))
                            ->helperText(__('accounting.account.is_control_help')),
                        Toggle::make('requires_member')
                            ->label(__('accounting.account.requires_member'))
                            ->helperText(__('accounting.account.requires_member_help'))
                            ->disabled($hasLines)
                            ->dehydrated(),
                        Textarea::make('description')
                            ->label(__('accounting.account.description'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Labels for the tier-2 change summary, in display order.
     *
     * @return array<string, string>
     */
    public static function summaryLabels(): array
    {
        return [
            'code' => __('accounting.account.code'),
            'type' => __('accounting.account.type'),
            'name_bn' => __('accounting.account.name_bn'),
            'name_en' => __('accounting.account.name_en'),
            'is_control' => __('accounting.account.is_control'),
            'requires_member' => __('accounting.account.requires_member'),
            'description' => __('accounting.account.description'),
        ];
    }

    /**
     * Normalise raw form state or a saved account into comparable, displayable values.
     *
     * @param  array<string, mixed>|Account  $source
     * @return array<string, mixed>
     */
    public static function summaryValues(array|Account $source): array
    {
        $value = fn (string $key): mixed => $source instanceof Account ? $source->getAttribute($key) : ($source[$key] ?? null);
        $type = $value('type');

        return [
            'code' => $value('code'),
            'type' => $type instanceof AccountType ? $type : AccountType::tryFrom((string) $type),
            'name_bn' => $value('name_bn'),
            'name_en' => $value('name_en'),
            'is_control' => (bool) $value('is_control'),
            'requires_member' => (bool) $value('requires_member'),
            'description' => $value('description'),
        ];
    }
}
