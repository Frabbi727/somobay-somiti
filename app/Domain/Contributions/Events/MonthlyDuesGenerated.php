<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Events;

use App\Domain\Contributions\Reports\DueGenerationPlan;

/**
 * Fired after a month's dues exist; the advance engine (Phase 5) applies advances from here.
 */
final readonly class MonthlyDuesGenerated
{
    public function __construct(public DueGenerationPlan $result) {}
}
