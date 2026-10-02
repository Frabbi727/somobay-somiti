<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a posted journal entry. Append-only, like its entry.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $line_no
 * @property int $account_id
 * @property int|null $member_id
 * @property Money $debit_poisha
 * @property Money $credit_poisha
 * @property string|null $memo
 * @property-read Account $account
 * @property-read JournalEntry $entry
 */
final class JournalLine extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn (self $line) => throw ImmutableRecord::for(self::class, $line->getKey()));
        self::deleting(fn (self $line) => throw ImmutableRecord::for(self::class, $line->getKey()));
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_poisha' => MoneyCast::class,
            'credit_poisha' => MoneyCast::class,
        ];
    }
}
