<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\MembershipTerms\Schemas;

use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Filament\Support\Display;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MembershipTermsInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 4])
                ->schema([
                    TextEntry::make('version')->label(__('terms.admin.version'))->placeholder('—'),
                    TextEntry::make('status')->label(__('terms.admin.status'))->badge(),
                    TextEntry::make('published_at')
                        ->label(__('terms.admin.published_at'))
                        ->state(fn (MembershipTerms $record): ?string => $record->published_at === null ? null : Display::dateTime($record->published_at))
                        ->placeholder('—'),
                    TextEntry::make('publisher.name')->label(__('terms.admin.published_by'))->placeholder('—'),
                ]),
            Grid::make(['default' => 1, 'lg' => 2])
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('rules_bn')->label(__('terms.admin.rules_bn'))->extraAttributes(['class' => 'whitespace-pre-line']),
                    TextEntry::make('rules_en')->label(__('terms.admin.rules_en'))->extraAttributes(['class' => 'whitespace-pre-line']),
                    TextEntry::make('declaration_bn')->label(__('terms.admin.declaration_bn'))->extraAttributes(['class' => 'whitespace-pre-line']),
                    TextEntry::make('declaration_en')->label(__('terms.admin.declaration_en'))->extraAttributes(['class' => 'whitespace-pre-line']),
                ]),
        ]);
    }
}
