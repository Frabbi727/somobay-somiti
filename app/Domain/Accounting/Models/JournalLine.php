<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a journal entry. Posting and immutability rules arrive with PostJournal (P1.S2).
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $line_no
 * @property int $account_id
 * @property int|null $member_id
 * @property Money $debit_poisha
 * @property Money $credit_poisha
 * @property string|null $memo
 */
final class JournalLine extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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
