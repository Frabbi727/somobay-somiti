<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\RatePlans\Schemas;

use App\Domain\Contributions\Enums\DueType;
use App\Domain\Settings\Enums\AdvancePolicy;
use App\Domain\Settings\Enums\LateFeeBase;
use App\Domain\Settings\Enums\LateFeeFrequency;
use App\Domain\Settings\Enums\LateFeeMode;
use App\Domain\Settings\Enums\RegistrationFeePolicy;
use App\Domain\Settings\Models\RatePlan;
use App\Filament\Forms\Components\MoneyInput;
use App\Support\Money\Bps;
use App\Support\Time\YearMonth;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

final class RatePlanForm
{
    public static function configure(Schema $schema): Schema
    {
        $mode = function (Get $get): ?LateFeeMode {
            $state = $get('late_fee_mode');

            return $state instanceof LateFeeMode ? $state : LateFeeMode::tryFrom(is_string($state) ? $state : '');
        };

        return $schema
            ->components([
                Section::make(__('rates.plan.rates_section'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('effective_from')
                            ->label(__('rates.plan.effective_from'))
                            ->helperText(__('rates.plan.effective_from_help'))
                            ->type('month')
                            ->required()
                            ->regex('/^\d{4}-\d{2}$/')
                            ->default(fn (): string => (string) YearMonth::current()->next())
                            ->disabled(fn (?RatePlan $record): bool => $record !== null)
                            ->dehydrated(),
                        Toggle::make('is_retroactive')
                            ->label(__('rates.plan.is_retroactive'))
                            ->helperText(__('rates.plan.is_retroactive_help'))
                            ->columnSpan(2),
                        MoneyInput::make('share_unit_poisha')
                            ->label(__('rates.plan.share_unit'))
                            ->required(),
                        MoneyInput::make('service_charge_per_share_poisha')
                            ->label(__('rates.plan.service_charge'))
                            ->default('0'),
                        MoneyInput::make('registration_fee_per_share_poisha')
                            ->label(__('rates.plan.registration_fee'))
                            ->default('0'),
                    ]),
                Section::make(__('rates.plan.schedule_section'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('due_day')
                            ->label(__('rates.plan.due_day'))
                            ->helperText(__('rates.plan.due_day_help'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(28)
                            ->default(10)
                            ->required(),
                        TextInput::make('grace_days')
                            ->label(__('rates.plan.grace_days'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(60)
                            ->default(5)
                            ->required(),
                    ]),
                Section::make(__('rates.plan.late_fee'))
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('late_fee_mode')
                            ->label(__('rates.plan.late_fee_mode'))
                            ->options(LateFeeMode::class)
                            ->default(LateFeeMode::None->value)
                            ->required()
                            ->native(false)
                            ->live(),
                        MoneyInput::make('late_fee_fixed_poisha')
                            ->label(__('rates.plan.late_fee_fixed'))
                            ->visible(fn (Get $get): bool => $mode($get) === LateFeeMode::Fixed)
                            ->required(fn (Get $get): bool => $mode($get) === LateFeeMode::Fixed),
                        TextInput::make('late_fee_percent')
                            ->label(__('rates.plan.late_fee_percent'))
                            ->suffix('%')
                            ->inputMode('decimal')
                            ->placeholder('2.00')
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (is_string($value) && trim($value) !== '') {
                                    try {
                                        Bps::ofPercent($value);
                                    } catch (\InvalidArgumentException) {
                                        $fail(__('money.validation.invalid'));
                                    }
                                }
                            })
                            ->visible(fn (Get $get): bool => $mode($get) === LateFeeMode::Percent)
                            ->required(fn (Get $get): bool => $mode($get) === LateFeeMode::Percent),
                        Select::make('late_fee_base')
                            ->label(__('rates.plan.late_fee_base'))
                            ->options(LateFeeBase::class)
                            ->native(false)
                            ->visible(fn (Get $get): bool => $mode($get) === LateFeeMode::Percent)
                            ->required(fn (Get $get): bool => $mode($get) === LateFeeMode::Percent),
                        MoneyInput::make('late_fee_cap_poisha')
                            ->label(__('rates.plan.late_fee_cap'))
                            ->visible(fn (Get $get): bool => $mode($get) === LateFeeMode::Percent),
                        Select::make('late_fee_frequency')
                            ->label(__('rates.plan.late_fee_frequency'))
                            ->options(LateFeeFrequency::class)
                            ->native(false)
                            ->visible(fn (Get $get): bool => in_array($mode($get), [LateFeeMode::Fixed, LateFeeMode::Percent], true))
                            ->required(fn (Get $get): bool => in_array($mode($get), [LateFeeMode::Fixed, LateFeeMode::Percent], true)),
                    ]),
                Section::make(__('rates.plan.policies_section'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('advance_policy')
                            ->label(__('rates.plan.advance_policy'))
                            ->options(AdvancePolicy::class)
                            ->default(AdvancePolicy::ApplyAtCurrentRate->value)
                            ->required()
                            ->native(false),
                        Select::make('registration_fee_on_rate_increase')
                            ->label(__('rates.plan.registration_fee_on_rate_increase'))
                            ->options(RegistrationFeePolicy::class)
                            ->default(RegistrationFeePolicy::None->value)
                            ->required()
                            ->native(false),
                        Repeater::make('allocation_order')
                            ->label(__('rates.plan.allocation_order'))
                            ->simple(
                                Select::make('type')
                                    ->options(DueType::class)
                                    ->required()
                                    ->distinct()
                                    ->native(false),
                            )
                            ->default(DueType::defaultAllocationOrder())
                            ->reorderable()
                            ->addable(false)
                            ->deletable(false),
                        Textarea::make('notes')
                            ->label(__('rates.plan.notes'))
                            ->rows(3),
                    ]),
            ]);
    }

    /**
     * Form state for editing a draft.
     *
     * @return array<string, mixed>
     */
    public static function fillFrom(RatePlan $plan): array
    {
        return [
            'effective_from' => (string) $plan->effective_from,
            'is_retroactive' => $plan->is_retroactive,
            'share_unit_poisha' => $plan->share_unit_poisha,
            'service_charge_per_share_poisha' => $plan->service_charge_per_share_poisha,
            'registration_fee_per_share_poisha' => $plan->registration_fee_per_share_poisha,
            'due_day' => $plan->due_day,
            'grace_days' => $plan->grace_days,
            'late_fee_mode' => $plan->late_fee_mode->value,
            'late_fee_fixed_poisha' => $plan->late_fee_fixed_poisha,
            'late_fee_percent' => $plan->lateFeeBps()?->toPercentString(),
            'late_fee_base' => $plan->late_fee_base?->value,
            'late_fee_cap_poisha' => $plan->late_fee_cap_poisha,
            'late_fee_frequency' => $plan->late_fee_frequency?->value,
            'advance_policy' => $plan->advance_policy->value,
            'registration_fee_on_rate_increase' => $plan->registration_fee_on_rate_increase->value,
            'allocation_order' => $plan->allocation_order,
            'notes' => $plan->notes,
        ];
    }
}
