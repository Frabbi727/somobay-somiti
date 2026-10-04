<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Statements;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\StatementImport;
use App\Domain\Accounting\Models\StatementLine;
use App\Domain\Accounting\Services\Accounts;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds the journal line a statement line corresponds to: same account, same amount and
 * direction, within a few days, not already matched. A reference that appears in the
 * voucher (TrxID, cheque or slip number) breaks ties; anything still ambiguous is left
 * for a person to decide.
 */
final class StatementMatcher
{
    public const int WINDOW_DAYS = 3;

    public function __construct(private readonly Accounts $accounts) {}

    /**
     * @return Collection<int, JournalLine>
     */
    public function candidates(StatementLine $line, int $days = self::WINDOW_DAYS): Collection
    {
        $account = $this->accounts->byCode($line->method->accountCode());
        $amount = $line->amount_poisha->absolute();

        return JournalLine::query()
            ->with('entry.source')
            ->where('account_id', $account->id)
            ->where($line->isInflow() ? 'debit_poisha' : 'credit_poisha', $amount->poisha)
            ->whereNotIn('id', StatementLine::query()->whereNotNull('journal_line_id')->select('journal_line_id'))
            ->whereHas('entry', fn (Builder $query) => $query->whereBetween('entry_date', [
                $line->transacted_on->subDays($days)->toDateString(),
                $line->transacted_on->addDays($days)->toDateString(),
            ]))
            ->orderBy('id')
            ->get();
    }

    /**
     * The single obvious match, or null when there is none or more than one.
     */
    public function pick(StatementLine $line): ?JournalLine
    {
        $candidates = $this->candidates($line);

        if ($candidates->count() <= 1) {
            return $candidates->first();
        }

        $byReference = $candidates->filter(fn (JournalLine $candidate): bool => $this->referenceAppears($line, $candidate));

        if ($byReference->count() === 1) {
            return $byReference->first();
        }

        if ($byReference->count() > 1) {
            return null;
        }

        $sameDay = $candidates->filter(fn (JournalLine $candidate): bool => $candidate->entry->entry_date->isSameDay($line->transacted_on));

        return $sameDay->count() === 1 ? $sameDay->first() : null;
    }

    /**
     * Matches every unmatched line of the import that has exactly one obvious counterpart.
     */
    public function autoMatch(StatementImport $import, User $actor): int
    {
        $matched = 0;

        $lines = $import->lines()
            ->where('status', StatementLineStatus::Unmatched)
            ->orderBy('transacted_on')
            ->orderBy('line_no')
            ->get();

        foreach ($lines as $line) {
            $journalLine = $this->pick($line);

            if ($journalLine !== null) {
                $line->update([
                    'status' => StatementLineStatus::Matched,
                    'journal_line_id' => $journalLine->id,
                    'matched_by' => $actor->id,
                    'matched_at' => CarbonImmutable::now(),
                ]);
                $matched++;
            }
        }

        return $matched;
    }

    private function referenceAppears(StatementLine $line, JournalLine $candidate): bool
    {
        $reference = trim((string) $line->reference);

        if (mb_strlen($reference) < 4) {
            return false;
        }

        $entry = $candidate->entry;
        $source = $entry->source;
        $sourceReferences = $source === null ? '' : implode(' ', array_filter(
            [$source->getAttribute('trx_id'), $source->getAttribute('reference')],
            fn (mixed $value): bool => is_string($value),
        ));

        return str_contains(mb_strtolower($entry->voucher_no.' '.$entry->narration.' '.$sourceReferences), mb_strtolower($reference));
    }
}
