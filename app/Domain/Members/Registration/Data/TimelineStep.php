<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Data;

use App\Domain\Members\Registration\Enums\TimelineState;
use Carbon\CarbonImmutable;

/**
 * One line of the registration status the member sees ("✓ Secretary approval").
 */
final readonly class TimelineStep
{
    public function __construct(
        public string $key,
        public string $label,
        public TimelineState $state,
        public ?CarbonImmutable $actedAt = null,
        public ?string $actorName = null,
        public ?string $reason = null,
    ) {}
}
