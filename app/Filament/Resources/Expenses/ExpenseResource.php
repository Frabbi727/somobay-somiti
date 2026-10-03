<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses;

use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Models\Expense;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Expenses\Schemas\ExpenseInfolist;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Filament\Support\Display;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Accounting;

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    public static function getModelLabel(): string
    {
        return __('expenses.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('expenses.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Expense::query()->where('status', ExpenseStatus::Pending)->count();

        return $pending === 0 ? null : Display::digits($pending);
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
        ];
    }
}
