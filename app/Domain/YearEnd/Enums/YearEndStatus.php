<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum YearEndStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function getLabel(): string
    {
        return __('year_end.status.'.$this->value);
    }

    public function getColor(): string
    {
        return $this === self::Posted ? 'success' : 'warning';
    }
}
