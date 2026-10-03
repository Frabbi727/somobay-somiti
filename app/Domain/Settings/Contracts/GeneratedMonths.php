<?php

declare(strict_types=1);

namespace App\Domain\Settings\Contracts;

use App\Support\Time\YearMonth;

/**
 * Tells rate-plan rules which months already have dues (BR-7). The dues engine (Phase 4)
 * binds the real implementation; until then no month counts as generated.
 */
interface GeneratedMonths
{
    public function latest(): ?YearMonth;
}
