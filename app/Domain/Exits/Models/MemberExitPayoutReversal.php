<?php

declare(strict_types=1);

namespace App\Domain\Exits\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Models\User;
use App\Policies\MemberExitPayoutReversalPolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An exit payout undone by a mirror voucher (append-only): who paid it, who reversed it and why.
 *
 * @property int $id
 * @property int $member_exit_id
 * @property Money $amount_poisha
 * @property int $payout_journal_entry_id
 * @property int $reversal_journal_entry_id
 * @property int $paid_by
 * @property int $reversed_by
 * @property string $reason
 * @property CarbonImmutable $created_at
 * @property-read JournalEntry $reversalEntry
 * @property-read User $reverser
 */
#[UsePolicy(MemberExitPayoutReversalPolicy::class)]
final class MemberExitPayoutReversal extends Model
{
    use LogsActivity;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    protected function casts(): array
    {
        return [
            'amount_poisha' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('exits');
    }
}
