<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\StatementLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Returns a matched or ignored statement line to the unmatched list.
 */
final class UnmatchStatementLine
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, StatementLine $line): StatementLine
    {
        return $this->causer->withCauser($actor, fn (): StatementLine => DB::transaction(function () use ($actor, $line): StatementLine {
            $locked = StatementLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('unmatch', $locked);

            $locked->update([
                'status' => StatementLineStatus::Unmatched,
                'journal_line_id' => null,
                'matched_by' => null,
                'matched_at' => null,
                'ignore_reason' => null,
            ]);

            return $locked;
        }, attempts: 3));
    }
}
