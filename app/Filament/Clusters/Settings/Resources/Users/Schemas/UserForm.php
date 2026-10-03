<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Resources\Users\Schemas;

use App\Domain\Settings\Services\StaffUserRules;
use App\Enums\Role;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $creating = fn (?User $record): bool => $record === null;

        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')->label(__('users.field.name'))->required()->maxLength(255),
                    TextInput::make('email')->label(__('users.field.email'))->email()->required()->maxLength(255),
                    TextInput::make('mobile')
                        ->label(__('users.field.mobile'))
                        ->helperText(__('users.field.mobile_help'))
                        ->tel()
                        ->maxLength(20),
                    Select::make('locale')
                        ->label(__('users.field.locale'))
                        ->options(['bn' => 'বাংলা', 'en' => 'English'])
                        ->default('bn')
                        ->required()
                        ->native(false),
                    CheckboxList::make('roles')
                        ->label(__('users.field.roles'))
                        ->options(self::roleOptions())
                        ->required()
                        ->columns(3)
                        ->columnSpanFull(),
                    TextInput::make('password')
                        ->label(fn (?User $record): string => __($record === null ? 'users.field.password' : 'users.field.new_password'))
                        ->helperText(fn (?User $record): ?string => $record === null ? null : (string) __('users.field.new_password_help'))
                        ->password()
                        ->revealable()
                        ->required($creating)
                        ->minLength(StaffUserRules::MIN_PASSWORD_LENGTH)
                        ->dehydrated(fn (?string $state): bool => filled($state)),
                ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        $options = [];

        foreach (Role::cases() as $role) {
            if ($role !== Role::Member) {
                $options[$role->value] = $role->getLabel();
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function summaryLabels(): array
    {
        return [
            'name' => __('users.field.name'),
            'email' => __('users.field.email'),
            'mobile' => __('users.field.mobile'),
            'locale' => __('users.field.locale'),
            'roles' => __('users.field.roles'),
            'password' => __('users.field.password'),
        ];
    }

    /**
     * @param  array<string, mixed>|User  $source
     * @return array<string, mixed>
     */
    public static function summaryValues(array|User $source): array
    {
        $roles = $source instanceof User ? $source->getRoleNames()->all() : (array) ($source['roles'] ?? []);
        $labels = array_map(fn (mixed $role): string => Role::from((string) $role)->getLabel(), $roles);
        sort($labels);

        return [
            'name' => $source instanceof User ? $source->name : ($source['name'] ?? null),
            'email' => $source instanceof User ? $source->email : ($source['email'] ?? null),
            'mobile' => $source instanceof User ? $source->mobile : ($source['mobile'] ?? null),
            'locale' => $source instanceof User ? $source->locale : ($source['locale'] ?? null),
            'roles' => implode(', ', $labels),
            'password' => $source instanceof User ? '' : (filled($source['password'] ?? null) ? '••••••••' : ''),
        ];
    }
}
