<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use App\Domain\Members\Enums\Gender;
use Closure;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The paper form's personal block shared by the member and each nominee: gender, passport number,
 * the present address and the permanent address (hidden when it is the same as the present one).
 */
final class PersonFields
{
    /**
     * @param  bool|Closure  $required  whether gender and the addresses must be filled (may take `$operation`)
     * @return list<Component>
     */
    public static function make(bool|Closure $required = true): array
    {
        return [
            Radio::make('gender')
                ->label(__('members.member.gender'))
                ->options(Gender::class)
                ->inline()
                ->required($required),
            TextInput::make('passport_no')
                ->label(__('members.member.passport_no'))
                ->maxLength(50),
            ...AddressFields::make('present', __('members.address.present'), $required),
            Toggle::make('permanent_same_as_present')
                ->label(__('members.member.same_as_present'))
                ->live()
                ->dehydrated()
                ->default(false)
                ->columnSpanFull(),
            ...array_map(
                fn ($fieldset) => $fieldset->visible(fn (Get $get): bool => ! (bool) $get('permanent_same_as_present')),
                AddressFields::make('permanent', __('members.address.permanent'), $required),
            ),
        ];
    }
}
