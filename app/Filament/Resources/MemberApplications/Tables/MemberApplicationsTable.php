<?php

declare(strict_types=1);

namespace App\Filament\Resources\MemberApplications\Tables;

use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class MemberApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('mobile')->label(__('registration.field.mobile'))->searchable(),
                TextColumn::make('name_bn')->label(__('members.member.name_bn'))->placeholder('—')->searchable(),
                TextColumn::make('status')->label(__('members.member.status'))->badge(),
                TextColumn::make('current_step')
                    ->label(__('registration.field.current_step'))
                    ->state(fn (MemberApplication $record): ?string => $record->currentRole()?->getLabel())
                    ->placeholder('—'),
                TextColumn::make('submitted_at')->label(__('registration.field.submitted_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('members.member.status'))->options(MemberApplicationStatus::class),
                Filter::make('waiting_for_me')
                    ->label(__('registration.filters.waiting_for_me'))
                    ->query(function (Builder $query): Builder {
                        /** @var User $user */
                        $user = auth()->user();

                        /** @var Builder<MemberApplication> $query */
                        return $query->waitingFor($user);
                    }),
            ])
            ->recordActions([
                ViewAction::make()->icon(Heroicon::OutlinedEye)->color('gray')->tooltip(__('registration.singular')),
            ]);
    }
}
