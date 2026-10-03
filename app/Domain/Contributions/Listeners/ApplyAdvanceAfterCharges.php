<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Listeners;

use App\Domain\Contributions\Actions\ApplyAdvance;

/**
 * W2/W5: after new dues or late fees exist, members' advances settle them automatically.
 */
final class ApplyAdvanceAfterCharges
{
    public function __construct(private readonly ApplyAdvance $apply) {}

    public function handle(object $event): void
    {
        ($this->apply)();
    }
}
