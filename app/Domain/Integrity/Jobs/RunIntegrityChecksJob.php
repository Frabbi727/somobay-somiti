<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Jobs;

use App\Domain\Integrity\Actions\RunIntegrityChecks;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The nightly integrity job (W7); also queued by "Run now" on the integrity report.
 */
final class RunIntegrityChecksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(public readonly ?int $requestedBy = null) {}

    public function handle(RunIntegrityChecks $run): void
    {
        $run($this->requestedBy === null ? null : User::query()->find($this->requestedBy));
    }
}
