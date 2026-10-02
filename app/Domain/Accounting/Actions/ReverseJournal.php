<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Cancels a posted entry by posting its mirror image as a JV (BR-17). Nothing is deleted:
 * the reversal points at the original through reverses_id, and the two net to zero.
 */
final class ReverseJournal
{
    public const int MIN_REASON_LENGTH = 5;

    public function __construct(private readonly PostJournal $post) {}

    public function __invoke(User $actor, JournalEntry $entry, string $reason, ?CarbonImmutable $date = null): JournalEntry
    {
        Gate::forUser($actor)->authorize('reverse', $entry);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => self::MIN_REASON_LENGTH]);
        }

        return DB::transaction(function () use ($actor, $entry, $reason, $date): JournalEntry {
            $original = JournalEntry::query()->whereKey($entry->getKey())->lockForUpdate()->with('lines')->firstOrFail();

            if ($original->isReversal()) {
                throw DomainRuleViolation::because('journal.errors.cannot_reverse_reversal', ['voucher' => $original->voucher_no]);
            }

            if ($original->reversal()->exists()) {
                throw DomainRuleViolation::because('journal.errors.already_reversed', ['voucher' => $original->voucher_no]);
            }

            $lines = array_values($original->lines->map(fn (JournalLine $line): JournalLineData => (new JournalLineData(
                accountId: $line->account_id,
                debit: $line->debit_poisha,
                credit: $line->credit_poisha,
                memberId: $line->member_id,
                memo: $line->memo,
            ))->mirrored())->all());

            $reversal = ($this->post)(
                $actor,
                new JournalEntryData(
                    type: VoucherType::Journal,
                    entryDate: $date ?? CarbonImmutable::now(YearMonth::TIMEZONE)->startOfDay(),
                    narration: '↺ '.$original->voucher_no.': '.$original->narration,
                    lines: $lines,
                    source: $original,
                    reason: $reason,
                ),
                reverses: $original,
                allowInactiveAccounts: true,
            );

            activity('accounting')
                ->performedOn($original)
                ->causedBy($actor)
                ->event('reversed')
                ->withProperties(['reversal' => $reversal->voucher_no, 'reason' => $reason])
                ->log('reversed');

            return $reversal;
        }, attempts: 3);
    }
}
