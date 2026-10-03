<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

use Filament\Support\Contracts\HasLabel;

enum ApprovalDecision: string implements HasLabel
{
    case Approve = 'approve';
    case Reject = 'reject';

    public function getLabel(): string
    {
        return __('rates.approval_decision.'.$this->value);
    }
}
