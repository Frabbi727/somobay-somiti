<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Models\User;
use App\Policies\AdvanceLedgerEntryPolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One movement of a member's advance (2111): positive = held, negative = applied or refunded.
 * balance_after is the running balance and can never go below zero.
 *
 * @property int $id
 * @property int $member_id
 * @property AdvanceEntryKind $kind
 * @property Money $delta_poisha
 * @property Money $balance_after_poisha
 * @property int|null $payment_id
 * @property int|null $due_id
 * @property int|null $journal_entry_id
 * @property int|null $reverses_entry_id
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Due|null $due
 * @property-read Member $member
 * @property-read JournalEntry|null $journalEntry
 * @property-read User|null $creator
 * @property-read AdvanceLedgerEntry|null $reversal
 */
#[UsePolicy(AdvanceLedgerEntryPolicy::class)]
final class AdvanceLedgerEntry extends Model
{
    use LogsActivity;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn (self $row) => throw ImmutableRecord::for(self::class, $row->getKey()));
        self::deleting(fn (self $row) => throw ImmutableRecord::for(self::class, $row->getKey()));
    }

    /**
     * @return BelongsTo<Due, $this>
     */
    public function due(): BelongsTo
    {
        return $this->belongsTo(Due::class);
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The entry that undoes this one, if any (reverses_entry_id is unique).
     *
     * @return HasOne<AdvanceLedgerEntry, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AdvanceEntryKind::class,
            'delta_poisha' => MoneyCast::class,
            'balance_after_poisha' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('collections');
    }
}
