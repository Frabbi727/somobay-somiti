<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Checks;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalHasher;
use App\Domain\Integrity\Finding;
use Illuminate\Support\Facades\DB;

/**
 * Invariant 8: re-computing every voucher's SHA-256 chain gives the stored hashes, so no posted
 * row was changed after posting (even by someone with database access).
 */
final class JournalHashChain implements IntegrityCheck
{
    public function __construct(private readonly JournalHasher $hasher) {}

    public function key(): string
    {
        return 'journal_hash_chain';
    }

    public function run(): array
    {
        $findings = [];

        foreach (DB::table('voucher_sequences')->get() as $sequence) {
            $previous = null;

            JournalEntry::query()
                ->where('fiscal_year_id', $sequence->fiscal_year_id)
                ->where('voucher_type', $sequence->voucher_type)
                ->with('lines')
                ->orderBy('id')
                ->chunk(500, function ($entries) use (&$previous, &$findings): void {
                    foreach ($entries as $entry) {
                        if ($this->hasher->hashOf($entry, $previous) !== $entry->hash) {
                            $findings[] = new Finding($this->key(), "Voucher {$entry->voucher_no} does not match its integrity hash", ['journal_entry_id' => $entry->id]);
                        }

                        $previous = $entry->hash;
                    }
                });

            if ($previous !== $sequence->last_hash) {
                $findings[] = new Finding($this->key(), "Sequence {$sequence->voucher_type}/{$sequence->fiscal_year_id} ends on a different hash than recorded", ['type' => (string) $sequence->voucher_type]);
            }
        }

        return $findings;
    }
}
