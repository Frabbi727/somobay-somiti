<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\RatePlans\Schemas;

use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Enums\ApprovalDecision;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Models\RatePlanApproval;
use App\Filament\Clusters\Settings\Resources\RatePlans\Support\RatePlanPresenter;
use App\Filament\Support\Display;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class RatePlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $data = fn (RatePlan $record): RatePlanData => RatePlanData::fromPlan($record);

        return $schema
            ->components([
                Section::make()
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('code')->label(__('rates.plan.code'))->weight('bold'),
                        TextEntry::make('effective_from')
                            ->label(__('rates.plan.effective_from'))
                            ->state(fn (RatePlan $record): string => Display::yearMonth($record->effective_from)),
                        TextEntry::make('status')
                            ->label(__('rates.plan.status'))
                            ->badge()
                            ->helperText(fn (RatePlan $record): string => RatePlanPresenter::pendingRoles($record)),
                        TextEntry::make('creator.name')->label(__('rates.plan.created_by')),
                    ]),
                Section::make(__('rates.plan.rates_section'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('share_unit_poisha')
                            ->label(__('rates.plan.share_unit'))
                            ->state(fn (RatePlan $record): string => Display::money($record->share_unit_poisha))
                            ->weight('bold'),
                        TextEntry::make('service_charge_per_share_poisha')
                            ->label(__('rates.plan.service_charge'))
                            ->state(fn (RatePlan $record): string => Display::money($record->service_charge_per_share_poisha)),
                        TextEntry::make('registration_fee_per_share_poisha')
                            ->label(__('rates.plan.registration_fee'))
                            ->state(fn (RatePlan $record): string => Display::money($record->registration_fee_per_share_poisha)),
                        TextEntry::make('due_day')
                            ->label(__('rates.plan.due_day'))
                            ->state(fn (RatePlan $record): string => Display::digits($record->due_day)),
                        TextEntry::make('grace_days')
                            ->label(__('rates.plan.grace_days'))
                            ->state(fn (RatePlan $record): string => Display::digits($record->grace_days)),
                        TextEntry::make('late_fee')
                            ->label(__('rates.plan.late_fee'))
                            ->state(fn (RatePlan $record): string => RatePlanPresenter::lateFee($data($record))),
                    ]),
                Section::make(__('rates.plan.policies_section'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('advance_policy')->label(__('rates.plan.advance_policy')),
                        TextEntry::make('registration_fee_on_rate_increase')->label(__('rates.plan.registration_fee_on_rate_increase')),
                        TextEntry::make('allocation_order')
                            ->label(__('rates.plan.allocation_order'))
                            ->state(fn (RatePlan $record): string => RatePlanPresenter::allocationOrder($data($record))),
                        TextEntry::make('notes')->label(__('rates.plan.notes'))->placeholder('—')->columnSpanFull(),
                        TextEntry::make('cancelled_reason')
                            ->label(__('rates.plan.cancelled_reason'))
                            ->visible(fn (RatePlan $record): bool => $record->cancelled_reason !== null)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('rates.plan.approvals'))
                    ->columnSpanFull()
                    ->visible(fn (RatePlan $record): bool => $record->approvals()->exists())
                    ->schema([
                        RepeatableEntry::make('approvals')
                            ->hiddenLabel()
                            ->columns(5)
                            ->schema([
                                TextEntry::make('user.name')->hiddenLabel(),
                                TextEntry::make('role')->hiddenLabel(),
                                TextEntry::make('decision')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->color(fn (RatePlanApproval $record): string => $record->decision === ApprovalDecision::Approve ? 'success' : 'danger'),
                                TextEntry::make('comment')->hiddenLabel()->placeholder('—'),
                                TextEntry::make('created_at')
                                    ->hiddenLabel()
                                    ->state(fn (RatePlanApproval $record): string => Display::dateTime($record->created_at)),
                            ]),
                    ]),
            ]);
    }
}
