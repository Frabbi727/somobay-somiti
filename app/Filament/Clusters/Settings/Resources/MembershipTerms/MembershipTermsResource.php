<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\MembershipTerms;

use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Pages\EditMembershipTerms;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Pages\ListMembershipTerms;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Pages\ViewMembershipTerms;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Schemas\MembershipTermsForm;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Schemas\MembershipTermsInfolist;
use App\Filament\Clusters\Settings\Resources\MembershipTerms\Tables\MembershipTermsTable;
use App\Filament\Clusters\Settings\SettingsCluster;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The somiti rules and declaration: one draft at a time, published versions are read-only.
 */
final class MembershipTermsResource extends Resource
{
    protected static ?string $model = MembershipTerms::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'membership-terms';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 15;

    public static function getModelLabel(): string
    {
        return __('terms.admin.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('terms.admin.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return MembershipTermsForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MembershipTermsInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembershipTermsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembershipTerms::route('/'),
            'view' => ViewMembershipTerms::route('/{record}'),
            'edit' => EditMembershipTerms::route('/{record}/edit'),
        ];
    }
}
