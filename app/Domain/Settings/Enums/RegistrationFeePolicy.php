<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum RegistrationFeePolicy: string implements HasLabel
{
    case None = 'none';
    case Difference = 'difference';

    public function getLabel(): string
    {
        return __('rates.registration_fee_policy.'.$this->value);
    }
}
