<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\SmsTemplates;

use App\Domain\Notifications\Models\SmsTemplate;
use App\Domain\Notifications\Services\SmsSegments;
use App\Filament\Clusters\Settings\Resources\SmsTemplates\Pages\EditSmsTemplate;
use App\Filament\Clusters\Settings\Resources\SmsTemplates\Pages\ListSmsTemplates;
use App\Filament\Clusters\Settings\SettingsCluster;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SmsTemplateResource extends Resource
{
    protected static ?string $model = SmsTemplate::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'sms-templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('sms.template.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sms.template.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $body = fn (string $field, string $label): array => [
            Textarea::make($field)->label($label)->required()->rows(3)->maxLength(500)->live(debounce: 400),
            TextEntry::make($field.'_length')
                ->hiddenLabel()
                ->state(fn (Get $get): string => SmsSegments::describe((string) $get($field)))
                ->color('gray'),
        ];

        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('key')->label(__('sms.template.key'))->weight('bold'),
                    TextEntry::make('placeholders')
                        ->label(__('sms.template.placeholders'))
                        ->state(fn (SmsTemplate $record): string => implode('  ', array_map(fn (string $name): string => '{'.$name.'}', $record->key->placeholders()))),
                    ...$body('body_bn', __('sms.template.body_bn')),
                    ...$body('body_en', __('sms.template.body_en')),
                    Toggle::make('is_active')->label(__('sms.template.is_active')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('key')->label(__('sms.template.key'))->weight('bold'),
                TextColumn::make('body_bn')->label(__('sms.template.body_bn'))->wrap()->limit(90),
                TextColumn::make('segments')
                    ->label(__('sms.template.parts'))
                    ->state(fn (SmsTemplate $record): int => SmsSegments::count($record->body_bn)),
                IconColumn::make('is_active')->label(__('sms.template.is_active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()->iconButton()->tooltip(__('common.edit'))->icon(Heroicon::OutlinedPencilSquare)->color('warning'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsTemplates::route('/'),
            'edit' => EditSmsTemplate::route('/{record}/edit'),
        ];
    }
}
