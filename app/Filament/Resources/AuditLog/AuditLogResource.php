<?php

declare(strict_types=1);

namespace App\Filament\Resources\AuditLog;

use App\Domain\Audit\Models\AuditEntry;
use App\Filament\Navigation\NavGroup;
use App\Filament\Resources\AuditLog\Pages\ListAuditEntries;
use App\Filament\Resources\AuditLog\Pages\ViewAuditEntry;
use App\Filament\Support\Display;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Everything that was created, changed, approved, reversed or signed in — read-only (SOMITI_SPEC.md §8).
 */
final class AuditLogResource extends Resource
{
    protected static ?string $model = AuditEntry::class;

    protected static ?string $slug = 'audit-log';

    protected static string|UnitEnum|null $navigationGroup = NavGroup::Audit;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('audit.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('audit.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('audit.plural');
    }

    public static function eventLabel(?string $event): string
    {
        if ($event === null) {
            return '—';
        }

        $key = 'audit.event.'.$event;

        return __($key) === $key ? Str::headline($event) : (string) __($key);
    }

    public static function moduleLabel(?string $logName): string
    {
        $key = 'audit.module.'.($logName ?? 'default');

        return __($key) === $key ? Str::headline((string) $logName) : (string) __($key);
    }

    public static function eventColor(?string $event): string
    {
        return match ($event) {
            'created', 'signed_in', 'approved' => 'success',
            'updated', 'signed_out' => 'info',
            'deleted', 'reversed', 'rejected', 'sign_in_failed' => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('causer'))
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')->label(__('audit.field.at'))->formatStateUsing(fn (AuditEntry $record): string => Display::dateTime($record->created_at))->sortable(),
                TextColumn::make('causer.name')->label(__('audit.field.who'))->placeholder(__('audit.system'))->searchable(),
                TextColumn::make('event')->label(__('audit.field.action'))->badge()
                    ->formatStateUsing(fn (AuditEntry $record): string => self::eventLabel($record->event ?? $record->description))
                    ->color(fn (AuditEntry $record): string => self::eventColor($record->event)),
                TextColumn::make('subject_type')->label(__('audit.field.record'))
                    ->formatStateUsing(fn (AuditEntry $record): string => $record->subjectLabel().($record->subject_id !== null ? ' #'.Display::digits((string) $record->subject_id) : '')),
                TextColumn::make('log_name')->label(__('audit.field.module'))->badge()->color('gray')->formatStateUsing(fn (?string $state): string => self::moduleLabel($state))->visibleFrom('md'),
                TextColumn::make('description')->label(__('audit.field.description'))->limit(60)->searchable()->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('causer_id')->label(__('audit.field.who'))
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null) === null || $data['value'] === ''
                        ? $query
                        : $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', (int) $data['value'])),
                SelectFilter::make('event')->label(__('audit.field.action'))
                    ->options(fn (): array => AuditEntry::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')
                        ->mapWithKeys(fn (string $event): array => [$event => self::eventLabel($event)])->all()),
                SelectFilter::make('log_name')->label(__('audit.field.module'))
                    ->options(fn (): array => AuditEntry::query()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name')
                        ->mapWithKeys(fn (string $name): array => [$name => self::moduleLabel($name)])->all()),
                SelectFilter::make('subject_type')->label(__('audit.field.record'))
                    ->options(fn (): array => AuditEntry::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')
                        ->mapWithKeys(fn (string $type): array => [$type => (new AuditEntry(['subject_type' => $type]))->subjectLabel()])->all()),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__('reports.ledger.from'))->native(false),
                        DatePicker::make('until')->label(__('reports.ledger.until'))->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from): Builder => $q->whereDate('created_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until): Builder => $q->whereDate('created_at', '<=', $until))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('audit.details'))
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('created_at')->label(__('audit.field.at'))->state(fn (AuditEntry $record): string => Display::dateTime($record->created_at)),
                    TextEntry::make('causer.name')->label(__('audit.field.who'))->placeholder(__('audit.system')),
                    TextEntry::make('event')->label(__('audit.field.action'))->badge()
                        ->state(fn (AuditEntry $record): string => self::eventLabel($record->event ?? $record->description))
                        ->color(fn (AuditEntry $record): string => self::eventColor($record->event)),
                    TextEntry::make('subject_type')->label(__('audit.field.record'))
                        ->state(fn (AuditEntry $record): string => $record->subjectLabel().($record->subject_id !== null ? ' #'.Display::digits((string) $record->subject_id) : '')),
                    TextEntry::make('log_name')->label(__('audit.field.module'))->state(fn (AuditEntry $record): string => self::moduleLabel($record->log_name)),
                    TextEntry::make('description')->label(__('audit.field.description')),
                    TextEntry::make('ip')->label(__('audit.field.ip'))->state(fn (AuditEntry $record): string => (string) ($record->properties?->get('ip') ?? '—')),
                    TextEntry::make('agent')->label(__('audit.field.agent'))->state(fn (AuditEntry $record): string => (string) ($record->properties?->get('agent') ?? '—'))->columnSpan(2),
                ]),
            Section::make(__('audit.changes'))
                ->schema([ViewEntry::make('changes')->hiddenLabel()->view('filament.audit.changes')]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEntries::route('/'),
            'view' => ViewAuditEntry::route('/{record}'),
        ];
    }
}
