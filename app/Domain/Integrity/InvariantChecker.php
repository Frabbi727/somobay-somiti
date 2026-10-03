<?php

declare(strict_types=1);

namespace App\Domain\Integrity;

use App\Domain\Integrity\Checks\IntegrityCheck;
use Throwable;

/**
 * Runs every registered §6.7 check. A check that crashes is itself reported as a finding.
 */
final class InvariantChecker
{
    /**
     * @param  iterable<IntegrityCheck>  $checks
     */
    public function __construct(private readonly iterable $checks = []) {}

    /**
     * @return list<string> messages only (handy in tests)
     */
    public function findings(): array
    {
        return array_map(fn (Finding $finding): string => $finding->message, $this->run()['findings']);
    }

    /**
     * @return array{checks: int, findings: list<Finding>}
     */
    public function run(): array
    {
        $count = 0;
        $findings = [];

        foreach ($this->checks as $check) {
            $count++;

            try {
                array_push($findings, ...$check->run());
            } catch (Throwable $exception) {
                $findings[] = new Finding($check->key(), 'Check failed to run: '.$exception->getMessage());
            }
        }

        return ['checks' => $count, 'findings' => $findings];
    }
}
