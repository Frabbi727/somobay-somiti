<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Integrity\Actions\RunIntegrityChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('somiti:integrity:check')]
#[Description('Run the integrity checks (balanced books, sub-ledgers, allocations, hash chain…) and store the result')]
final class RunIntegrityChecksCommand extends Command
{
    public function handle(RunIntegrityChecks $run): int
    {
        $result = $run();

        foreach ($result->findings()->orderBy('id')->get() as $finding) {
            $this->error("[{$finding->check}] {$finding->message}");
        }

        $this->info(sprintf('%d check(s) run, %d finding(s): %s.', $result->checks_run, $result->findings_count, $result->status->value));

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }
}
