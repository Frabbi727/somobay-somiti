<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Data;

use Carbon\CarbonImmutable;

/**
 * One thing the user entered, as the "My submissions" list shows it.
 */
final readonly class SubmissionRow
{
    public function __construct(
        public string $type,
        public string $reference,
        public string $amount,
        public string $statusLabel,
        public string $statusColor,
        public CarbonImmutable $enteredAt,
        public ?string $waitingFor,
        public string $url,
    ) {}
}
