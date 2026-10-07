<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberApplications\Schemas;

use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Models\MemberApplicationNominee;
use App\Domain\Members\Registration\Services\RegistrationTimeline;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MemberApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (MemberApplication $record): string => app(RegistrationTimeline::class)->headline($record))
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('timeline')
                        ->hiddenLabel()
                        ->view('filament.registration.timeline')
                        ->state(fn (MemberApplication $record): array => app(RegistrationTimeline::class)->steps($record)),
                ]),
            Section::make(__('members.member.personal_section'))
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    ImageEntry::make('photo_path')->hiddenLabel()->disk('local')->visibility('private')->circular()
                        ->visible(fn (MemberApplication $record): bool => $record->photo_path !== null),
                    TextEntry::make('name_bn')->label(__('members.member.name_bn'))->placeholder('—'),
                    TextEntry::make('name_en')->label(__('members.member.name_en'))->placeholder('—'),
                    TextEntry::make('guardian_name')->label(__('members.member.guardian_name'))->placeholder('—'),
                    TextEntry::make('nid')->label(__('members.member.nid'))->placeholder('—'),
                    TextEntry::make('date_of_birth')->label(__('members.member.date_of_birth'))->date()->placeholder('—'),
                    TextEntry::make('mobile')->label(__('members.member.mobile')),
                    TextEntry::make('email')->label(__('members.member.email'))->placeholder('—'),
                    TextEntry::make('requested_shares')->label(__('registration.field.requested_shares'))->placeholder('—'),
                    TextEntry::make('address')->label(__('members.member.address'))->placeholder('—')->columnSpanFull(),
                ]),
            Section::make(__('members.member.nominees_section'))
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('nominees')
                        ->hiddenLabel()
                        ->columns(5)
                        ->schema([
                            TextEntry::make('name')->hiddenLabel()->weight('bold'),
                            TextEntry::make('relation_id')->hiddenLabel()->state(fn (MemberApplicationNominee $record): string => $record->nomineeRelation?->label() ?? '—'),
                            TextEntry::make('nid')->hiddenLabel()->placeholder('—'),
                            TextEntry::make('mobile')->hiddenLabel()->placeholder('—'),
                            TextEntry::make('share_bps')->hiddenLabel()->state(fn (MemberApplicationNominee $record): string => $record->share()->format(app()->getLocale())),
                        ]),
                ]),
        ]);
    }
}
