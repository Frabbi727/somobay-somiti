<?php

declare(strict_types=1);

namespace App\Filament\Resources\Meetings\Schemas;

use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Governance\Models\MeetingAttendee;
use App\Filament\Support\Display;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MeetingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('meeting_no')->label(__('governance.field.number'))->weight('bold'),
                    TextEntry::make('type')->label(__('governance.field.type'))->badge(),
                    TextEntry::make('status')->label(__('governance.field.status'))->badge(),
                    TextEntry::make('scheduled_at')
                        ->label(__('governance.field.scheduled_at'))
                        ->formatStateUsing(fn (Meeting $record): string => Display::dateTime($record->scheduled_at)),
                    TextEntry::make('venue')->label(__('governance.field.venue'))->placeholder('—'),
                    TextEntry::make('quorum')
                        ->label(__('governance.field.quorum'))
                        ->state(fn (Meeting $record): string => $record->status === MeetingStatus::Held
                            ? __('governance.field.quorum_value', [
                                'present' => Display::digits((int) $record->attendees_count),
                                'eligible' => Display::digits((int) $record->eligible_count),
                                'required' => Display::digits((int) $record->quorum_required),
                            ])
                            : '—')
                        ->color(fn (Meeting $record): ?string => $record->quorum_met === null ? null : ($record->quorum_met ? 'success' : 'danger')),
                    TextEntry::make('agenda')->label(__('governance.field.agenda'))->placeholder('—')->columnSpanFull(),
                    TextEntry::make('attendees')
                        ->label(__('governance.field.attendees'))
                        ->state(function (Meeting $record): string {
                            $attendees = $record->attendees()->with(['member', 'user'])->orderBy('id')->get();

                            return $attendees->isEmpty()
                                ? '—'
                                : $attendees->map(fn (MeetingAttendee $attendee): string => $attendee->member?->displayName() ?? $attendee->user->name ?? '')->implode(', ');
                        })
                        ->columnSpanFull(),
                    TextEntry::make('minutes')->label(__('governance.field.minutes'))->placeholder('—')->columnSpanFull(),
                    TextEntry::make('cancel_reason')->label(__('governance.field.cancel_reason'))->visible(fn (Meeting $record): bool => $record->cancel_reason !== null),
                ]),
        ]);
    }
}
