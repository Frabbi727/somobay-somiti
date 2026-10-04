<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\StatementLine;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * A person links a statement line to the journal line it corresponds to (any date; same
 * account, amount and direction; each journal line at most once).
 */
final class MatchStatementLine
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, StatementLine $line, JournalLine $journalLine): StatementLine
    {
        return $this->causer->withCauser($actor, fn (): StatementLine => DB::transaction(function () use ($actor, $line, $journalLine): StatementLine {
            $locked = StatementLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('match', $locked);

            $account = $this->accounts->byCode($locked->method->accountCode());
            $amount = $locked->amount_poisha->absolute();
            $side = $locked->isInflow() ? $journalLine->debit_poisha : $journalLine->credit_poisha;

            if ($journalLine->account_id !== $account->id || ! $side->equals($amount)) {
                throw DomainRuleViolation::because('statements.errors.mismatch');
            }

            if (StatementLine::query()->where('journal_line_id', $journalLine->id)->exists()) {
                throw DomainRuleViolation::because('statements.errors.already_matched');
            }

            $locked->update([
                'status' => StatementLineStatus::Matched,
                'journal_line_id' => $journalLine->id,
                'matched_by' => $actor->id,
                'matched_at' => CarbonImmutable::now(),
                'ignore_reason' => null,
            ]);

            return $locked;
        }, attempts: 3));
    }
}
