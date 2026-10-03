<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\Schemas\PaymentInfolist;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Filament\Support\Display;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

final class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Collections;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('payments.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payments.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Payment::query()->where('status', PaymentStatus::Pending)->count();

        return $pending === 0 ? null : Display::digits($pending);
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
