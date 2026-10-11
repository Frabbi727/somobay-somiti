<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Data;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Something waiting for a person to act on it: who can act is decided by the policy $ability on
 * $record, the same check that shows the button.
 */
final readonly class PendingItem
{
    /**
     * @param  string  $type  "payment", "expense", "rate_plan"…
     * @param  string  $ability  policy ability that acts on it ("approve", "pay", "post", "decide")
     */
    public function __construct(
        public string $type,
        public Model $record,
        public string $ability,
        public CarbonImmutable $since,
    ) {}

    /**
     * Stable key for one queue, e.g. "payment.approve" or "exit.pay".
     */
    public function queue(): string
    {
        return $this->type.'.'.$this->ability;
    }
}
