<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Actions;

use App\Domain\Integrity\Enums\IntegrityRunStatus;
use App\Domain\Integrity\Events\IntegrityCheckFailed;
use App\Domain\Integrity\Finding;
use App\Domain\Integrity\InvariantChecker;
use App\Domain\Integrity\Models\IntegrityRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Runs every §6.7 check and stores the outcome (W7). A failure raises IntegrityCheckFailed,
 * which alerts the super admins and accountants. Read-only towards the books.
 */
final class RunIntegrityChecks
{
    public function __construct(private readonly InvariantChecker $checker) {}

    public function __invoke(?User $actor = null): IntegrityRun
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('runIntegrityChecks');
        }

        $run = IntegrityRun::query()->create([
            'status' => IntegrityRunStatus::Running,
            'triggered_by' => $actor?->id,
            'started_at' => CarbonImmutable::now(),
        ]);

        // One repeatable-read snapshot, so postings made while the checks run can't produce false
        // alarms. (Only possible at the outermost level; tests already run inside a transaction.)
        $outermost = DB::transactionLevel() === 0;

        ['checks' => $checks, 'findings' => $findings] = DB::transaction(function () use ($outermost): array {
            if ($outermost) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            return $this->checker->run();
        });

        DB::transaction(function () use ($run, $checks, $findings): void {
            foreach (array_chunk($findings, 500) as $chunk) {
                $run->findings()->insert(array_map(fn (Finding $finding): array => [
                    'integrity_run_id' => $run->id,
                    'check' => $finding->check,
                    'message' => $finding->message,
                    'context' => $finding->context === [] ? null : json_encode($finding->context, JSON_THROW_ON_ERROR),
                ], $chunk));
            }

            $run->update([
                'status' => $findings === [] ? IntegrityRunStatus::Passed : IntegrityRunStatus::Failed,
                'checks_run' => $checks,
                'findings_count' => count($findings),
                'finished_at' => CarbonImmutable::now(),
            ]);
        });

        if ($run->failed()) {
            IntegrityCheckFailed::dispatch($run);
        }

        return $run;
    }
}
