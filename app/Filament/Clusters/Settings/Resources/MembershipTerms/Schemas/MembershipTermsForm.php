<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\MembershipTerms\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

final class MembershipTermsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])
                ->columnSpanFull()
                ->schema([
                    Textarea::make('rules_bn')->label(__('terms.admin.rules_bn'))->required()->rows(14),
                    Textarea::make('rules_en')->label(__('terms.admin.rules_en'))->required()->rows(14),
                    Textarea::make('declaration_bn')->label(__('terms.admin.declaration_bn'))->required()->rows(12),
                    Textarea::make('declaration_en')->label(__('terms.admin.declaration_en'))->required()->rows(12),
                ]),
        ]);
    }
}
