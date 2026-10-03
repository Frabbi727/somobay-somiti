<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Events;

use App\Domain\Integrity\Models\IntegrityRun;
use Illuminate\Foundation\Events\Dispatchable;

final class IntegrityCheckFailed
{
    use Dispatchable;

    public function __construct(public readonly IntegrityRun $run) {}
}
