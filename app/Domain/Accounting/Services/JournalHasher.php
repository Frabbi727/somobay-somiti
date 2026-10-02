<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;

/**
 * SHA-256 hash chain over posted entries, one chain per voucher sequence (§6.7 invariant 8).
 * Each hash covers the previous hash plus a canonical form of the entry and its lines,
 * so editing any posted row afterwards breaks every later hash in the chain.
 */
final class JournalHasher
{
    /**
     * @param  array{voucher_no: string, voucher_type: string, entry_date: string, narration: string, reason: string|null, reverses_id: int|null}  $entry
     * @param  list<array{line_no: int, account_id: int, member_id: int|null, debit_poisha: int, credit_poisha: int, memo: string|null}>  $lines
     */
    public function hash(?string $previousHash, array $entry, array $lines): string
    {
        return hash('sha256', ($previousHash ?? '').json_encode([$entry, $lines], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Recomputes the hash of a stored entry; it must equal the stored one.
     */
    public function hashOf(JournalEntry $entry, ?string $previousHash): string
    {
        return $this->hash($previousHash, [
            'voucher_no' => $entry->voucher_no,
            'voucher_type' => $entry->voucher_type->value,
            'entry_date' => $entry->entry_date->toDateString(),
            'narration' => $entry->narration,
            'reason' => $entry->reason,
            'reverses_id' => $entry->reverses_id,
        ], array_values($entry->lines->map(fn (JournalLine $line): array => [
            'line_no' => $line->line_no,
            'account_id' => $line->account_id,
            'member_id' => $line->member_id,
            'debit_poisha' => $line->debit_poisha->poisha,
            'credit_poisha' => $line->credit_poisha->poisha,
            'memo' => $line->memo,
        ])->all()));
    }
}
