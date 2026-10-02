<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case SuperAdmin = 'super_admin';
    case President = 'president';
    case Secretary = 'secretary';
    case Cashier = 'cashier';
    case Accountant = 'accountant';
    case Auditor = 'auditor';
    case Member = 'member';

    /**
     * Role names that may sign in to the staff panel.
     *
     * @return list<string>
     */
    public static function staff(): array
    {
        $staff = [];

        foreach (self::cases() as $role) {
            if ($role !== self::Member) {
                $staff[] = $role->value;
            }
        }

        return $staff;
    }

    public function getLabel(): string
    {
        return __('roles.'.$this->value);
    }
}
