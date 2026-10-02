<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Data;

use App\Domain\Accounting\Enums\VoucherType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

final readonly class JournalEntryData
{
    /**
     * @param  list<JournalLineData>  $lines
     */
    public function __construct(
        public VoucherType $type,
        public CarbonImmutable $entryDate,
        public string $narration,
        public array $lines,
        public ?Model $source = null,
        public ?string $reason = null,
    ) {}
}
