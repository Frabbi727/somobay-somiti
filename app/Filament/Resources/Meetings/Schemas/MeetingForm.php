<?php

declare(strict_types=1);

namespace App\Filament\Resources\Meetings\Schemas;

use App\Domain\Governance\Enums\MeetingType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MeetingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('type')
                        ->label(__('governance.field.type'))
                        ->options(MeetingType::class)
                        ->default(MeetingType::Committee->value)
                        ->required()
                        ->native(false),
                    DateTimePicker::make('scheduled_at')
                        ->label(__('governance.field.scheduled_at'))
                        ->seconds(false)
                        ->native(false)
                        ->required(),
                    TextInput::make('title')->label(__('governance.field.title'))->required()->minLength(3)->maxLength(255),
                    TextInput::make('venue')->label(__('governance.field.venue'))->maxLength(255),
                    Textarea::make('agenda')->label(__('governance.field.agenda'))->rows(5)->columnSpanFull(),
                ]),
        ]);
    }
}
