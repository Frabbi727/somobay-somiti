<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IntegrityRunStatus: string implements HasColor, HasLabel
{
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return __('integrity.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'gray',
            self::Passed => 'success',
            self::Failed => 'danger',
        };
    }
}
