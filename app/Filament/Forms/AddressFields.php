<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Domain\Geo\Services\GeoDirectory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Division, district, upazila, union and street line as chained searchable dropdowns. Only the upazila,
 * the union and the line are saved; division and district are shown to narrow the search.
 */
final class AddressFields
{
    /**
     * @param  string  $prefix  `present` or `permanent`; the saved keys are `{prefix}_upazila_id`, `{prefix}_union_id`, `{prefix}_address_line`
     * @param  bool|\Closure  $required  (may take `$operation`) whether the upazila (and its union, when it has any) and the street line must be filled
     * @return list<Fieldset>
     */
    public static function make(string $prefix, string $label, bool|\Closure $required = true): array
    {
        $division = $prefix.'_division_id';
        $district = $prefix.'_district_id';
        $upazila = $prefix.'_upazila_id';
        $union = $prefix.'_union_id';

        $geo = fn (): GeoDirectory => app(GeoDirectory::class);

        // The district the upazila list is for: the chosen one, else the saved upazila's own district.
        $districtOf = function (Get $get) use ($district, $upazila, $geo): ?int {
            if (is_numeric($get($district))) {
                return (int) $get($district);
            }

            return is_numeric($get($upazila)) ? $geo()->chainFor((int) $get($upazila))['district_id'] : null;
        };

        return [
            Fieldset::make($label)
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make($division)
                        ->label(__('members.address.division'))
                        ->options(fn (): array => $geo()->divisions())
                        ->getSearchResultsUsing(fn (string $search): array => $geo()->divisions($search))
                        ->searchable()
                        ->live()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Select $component, Get $get) use ($upazila, $geo): void {
                            $upazilaId = $get($upazila);

                            if (is_numeric($upazilaId) && $component->getState() === null) {
                                $component->state($geo()->chainFor((int) $upazilaId)['division_id']);
                            }
                        })
                        ->afterStateUpdated(function (Set $set) use ($district, $upazila, $union): void {
                            $set($district, null);
                            $set($upazila, null);
                            $set($union, null);
                        }),
                    Select::make($district)
                        ->label(__('members.address.district'))
                        ->options(fn (Get $get): array => is_numeric($get($division)) ? $geo()->districts((int) $get($division)) : [])
                        ->getSearchResultsUsing(fn (string $search, Get $get): array => is_numeric($get($division)) ? $geo()->districts((int) $get($division), $search) : [])
                        ->searchable()
                        ->live()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Select $component, Get $get) use ($upazila, $geo): void {
                            $upazilaId = $get($upazila);

                            if (is_numeric($upazilaId) && $component->getState() === null) {
                                $component->state($geo()->chainFor((int) $upazilaId)['district_id']);
                            }
                        })
                        ->afterStateUpdated(function (Set $set) use ($upazila, $union): void {
                            $set($upazila, null);
                            $set($union, null);
                        }),
                    Select::make($upazila)
                        ->label(__('members.address.upazila'))
                        ->options(fn (Get $get): array => ($id = $districtOf($get)) === null ? [] : $geo()->upazilas($id))
                        ->getSearchResultsUsing(fn (string $search, Get $get): array => ($id = $districtOf($get)) === null ? [] : $geo()->upazilas($id, $search))
                        ->searchable()
                        ->live()
                        ->required($required)
                        ->afterStateUpdated(fn (Set $set) => $set($union, null)),
                    Select::make($union)
                        ->label(__('members.address.union'))
                        ->options(fn (Get $get): array => is_numeric($get($upazila)) ? $geo()->unions((int) $get($upazila)) : [])
                        ->getSearchResultsUsing(fn (string $search, Get $get): array => is_numeric($get($upazila)) ? $geo()->unions((int) $get($upazila), $search) : [])
                        ->searchable()
                        ->required(fn (Select $component, Get $get): bool => (bool) $component->evaluate($required) && is_numeric($get($upazila)) && $geo()->upazilaHasUnions((int) $get($upazila))),
                    TextInput::make($prefix.'_address_line')
                        ->label(__('members.address.line'))
                        ->required($required)
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),
        ];
    }
}
