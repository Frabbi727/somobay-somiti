<?php

declare(strict_types=1);

namespace App\Filament\Resources\FundTransfers;

use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Models\FundTransfer;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\FundTransfers\Pages\CreateFundTransfer;
use App\Filament\Resources\FundTransfers\Pages\ListFundTransfers;
use App\Filament\Resources\FundTransfers\Pages\ViewFundTransfer;
use App\Filament\Resources\FundTransfers\Schemas\FundTransferForm;
use App\Filament\Resources\FundTransfers\Schemas\FundTransferInfolist;
use App\Filament\Resources\FundTransfers\Tables\FundTransfersTable;
use App\Filament\Support\Display;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class FundTransferResource extends Resource
{
    protected static ?string $model = FundTransfer::class;

    protected static ?string $slug = 'fund-transfers';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Accounting;

    protected static ?int $navigationSort = 25;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function getModelLabel(): string
    {
        return __('transfers.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('transfers.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = FundTransfer::query()->where('status', TransferStatus::Pending)->count();

        return $pending === 0 ? null : Display::digits($pending);
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return FundTransferForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FundTransferInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FundTransfersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundTransfers::route('/'),
            'create' => CreateFundTransfer::route('/create'),
            'view' => ViewFundTransfer::route('/{record}'),
        ];
    }
}
