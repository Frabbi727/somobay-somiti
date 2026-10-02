<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Schemas;

use App\Domain\Accounting\Models\Account;
use App\Filament\Support\Display;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class AccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')
                            ->label(__('accounting.account.code'))
                            ->formatStateUsing(fn (string $state): string => Display::digits($state))
                            ->weight('bold'),
                        TextEntry::make('name_bn')->label(__('accounting.account.name_bn')),
                        TextEntry::make('name_en')->label(__('accounting.account.name_en')),
                        TextEntry::make('type')->label(__('accounting.account.type'))->badge(),
                        TextEntry::make('normal_balance')->label(__('accounting.account.normal_balance'))->badge(),
                        IconEntry::make('is_active')->label(__('accounting.account.is_active'))->boolean(),
                        IconEntry::make('is_control')->label(__('accounting.account.is_control'))->boolean(),
                        IconEntry::make('requires_member')->label(__('accounting.account.requires_member'))->boolean(),
                        TextEntry::make('updated_at')
                            ->label(__('common.updated_at'))
                            ->state(fn (Account $record): string => Display::dateTime($record->updated_at)),
                        TextEntry::make('description')
                            ->label(__('accounting.account.description'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
