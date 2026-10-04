<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\StatementLine;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Marks a statement line as not needing a book entry (e.g. a reversed bank error), with a reason.
 */
final class IgnoreStatementLine
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, StatementLine $line, string $reason): StatementLine
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): StatementLine => DB::transaction(function () use ($actor, $line, $reason): StatementLine {
            $locked = StatementLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('ignore', $locked);

            $locked->update([
                'status' => StatementLineStatus::Ignored,
                'ignore_reason' => $reason,
                'matched_by' => $actor->id,
                'matched_at' => CarbonImmutable::now(),
            ]);

            return $locked;
        }, attempts: 3));
    }
}
