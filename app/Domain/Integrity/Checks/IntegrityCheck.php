<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Integrity\Finding;

/**
 * One invariant from SOMITI_SPEC.md §6.7. Register implementations with the "somiti.integrity_checks" tag.
 */
interface IntegrityCheck
{
    public function key(): string;

    /**
     * @return list<Finding> empty when the invariant holds
     */
    public function run(): array;
}
