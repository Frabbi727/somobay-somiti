<?php

declare(strict_types=1);

namespace App\Filament\Resources\Members\Tables;

use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\MemberShareSnapshot;
use App\Filament\Resources\Members\Actions\MemberActions;
use App\Filament\Support\Display;
use App\Support\Time\YearMonth;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->addSelect([
                'shares_now' => MemberShareSnapshot::query()
                    ->select('shares')
                    ->whereColumn('member_id', 'members.id')
                    ->where('effective_from', '<=', YearMonth::current()->toDateString())
                    ->orderByDesc('effective_from')
                    ->limit(1),
            ]))
            ->defaultSort('member_no')
            ->columns([
                ImageColumn::make('photo_path')
                    ->label('')
                    ->disk('local')
                    ->visibility('private')
                    ->circular()
                    ->visibleFrom('md'),
                TextColumn::make('member_no')
                    ->label(__('members.member.member_no'))
                    ->formatStateUsing(fn (string $state): string => Display::digits($state))
                    ->weight('bold')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label(__('members.member.name'))
                    ->state(fn (Member $record): string => Display::isBangla() ? $record->name_bn : $record->name_en)
                    ->description(fn (Member $record): string => Display::isBangla() ? $record->name_en : $record->name_bn)
                    ->searchable(['name_bn', 'name_en', 'nid']),
                TextColumn::make('mobile')
                    ->label(__('members.member.mobile'))
                    ->formatStateUsing(fn (string $state): string => Display::digits($state))
                    ->searchable(),
                TextColumn::make('shares_now')
                    ->label(__('members.member.shares_now'))
                    ->formatStateUsing(fn (?int $state): string => Display::digits($state ?? 0))
                    ->alignment(Alignment::End),
                TextColumn::make('status')
                    ->label(__('members.member.status'))
                    ->badge(),
                TextColumn::make('joined_on')
                    ->label(__('members.member.joined_on'))
                    ->formatStateUsing(fn (Member $record): string => Display::date($record->joined_on))
                    ->sortable()
                    ->visibleFrom('lg')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('members.member.status'))
                    ->options(MemberStatus::class)
                    ->default(MemberStatus::Active->value),
            ])
            ->recordActions([
                ViewAction::make()->iconButton()->tooltip(__('common.view'))->icon(Heroicon::OutlinedEye)->color('gray'),
                EditAction::make()->iconButton()->tooltip(__('common.edit'))->icon(Heroicon::OutlinedPencilSquare)->color('warning'),
                ActionGroup::make([
                    MemberActions::changeShares(),
                    MemberActions::setPortalPassword(),
                    MemberActions::deactivate(),
                    MemberActions::reactivate(),
                ])->iconButton()->tooltip(__('common.more')),
            ])
            ->emptyStateHeading(__('members.member.empty_heading'))
            ->emptyStateDescription(__('members.member.empty_description'))
            ->emptyStateIcon(Heroicon::OutlinedUserGroup);
    }
}
