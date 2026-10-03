<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\SmsMessages;

use App\Domain\Notifications\Enums\SmsStatus;
use App\Domain\Notifications\Models\SmsMessage;
use App\Filament\Clusters\Settings\Resources\SmsMessages\Pages\ListSmsMessages;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Support\Display;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SmsMessageResource extends Resource
{
    protected static ?string $model = SmsMessage::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static ?string $slug = 'sms-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('sms.log.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sms.log.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('member'))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')->label(__('sms.log.at'))->formatStateUsing(fn (SmsMessage $record): string => Display::dateTime($record->created_at)),
                TextColumn::make('to')->label(__('sms.log.to'))->formatStateUsing(fn (string $state): string => Display::digits($state))->searchable(),
                TextColumn::make('member.member_no')->label(__('payments.field.member'))->placeholder('—')->visibleFrom('md'),
                TextColumn::make('body')->label(__('sms.log.body'))->wrap()->limit(80)->searchable(),
                TextColumn::make('segments')->label(__('sms.template.parts')),
                TextColumn::make('status')->label(__('sms.log.status'))->badge()->description(fn (SmsMessage $record): ?string => $record->error),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('sms.log.status'))->options(SmsStatus::class),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsMessages::route('/'),
        ];
    }
}
