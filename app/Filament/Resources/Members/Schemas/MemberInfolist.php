<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Schemas;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Nominee;
use App\Filament\Support\Display;
use App\Support\Time\YearMonth;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MemberInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        ImageEntry::make('photo_path')
                            ->hiddenLabel()
                            ->disk('local')
                            ->visibility('private')
                            ->circular()
                            ->visible(fn (Member $record): bool => $record->photo_path !== null),
                        TextEntry::make('member_no')
                            ->label(__('members.member.member_no'))
                            ->formatStateUsing(fn (string $state): string => Display::digits($state))
                            ->weight('bold'),
                        TextEntry::make('name_bn')->label(__('members.member.name_bn')),
                        TextEntry::make('name_en')->label(__('members.member.name_en')),
                        TextEntry::make('status')
                            ->label(__('members.member.status'))
                            ->badge()
                            ->helperText(fn (Member $record): ?string => $record->deactivation_reason),
                        TextEntry::make('shares_now')
                            ->label(__('members.member.shares_now'))
                            ->state(fn (Member $record): string => Display::digits($record->sharesIn(YearMonth::current())))
                            ->weight('bold'),
                        TextEntry::make('mobile')
                            ->label(__('members.member.mobile'))
                            ->formatStateUsing(fn (string $state): string => Display::digits($state)),
                        TextEntry::make('nid')
                            ->label(__('members.member.nid'))
                            ->formatStateUsing(fn (string $state): string => Display::digits($state))
                            ->placeholder('—'),
                        TextEntry::make('guardian_name')->label(__('members.member.guardian_name'))->placeholder('—'),
                        TextEntry::make('joined_on')
                            ->label(__('members.member.joined_on'))
                            ->state(fn (Member $record): string => Display::date($record->joined_on)),
                        TextEntry::make('date_of_birth')
                            ->label(__('members.member.date_of_birth'))
                            ->state(fn (Member $record): string => Display::date($record->date_of_birth)),
                        TextEntry::make('email')->label(__('members.member.email'))->placeholder('—'),
                        TextEntry::make('address')->label(__('members.member.address'))->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make(__('members.member.nominees_section'))
                    ->columnSpanFull()
                    ->visible(fn (Member $record): bool => $record->nominees->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('nominees')
                            ->hiddenLabel()
                            ->columns(5)
                            ->schema([
                                TextEntry::make('name')->label(__('members.nominee.name'))->weight('bold'),
                                TextEntry::make('relation')->label(__('members.nominee.relation'))->state(fn (Nominee $record): string => $record->relationLabel()),
                                TextEntry::make('nid')->label(__('members.nominee.nid'))->placeholder('—'),
                                TextEntry::make('mobile')->label(__('members.nominee.mobile'))->placeholder('—'),
                                TextEntry::make('share_bps')
                                    ->label(__('members.nominee.share'))
                                    ->state(fn (Nominee $record): string => $record->share()->format(app()->getLocale())),
                            ]),
                    ]),
            ]);
    }
}
