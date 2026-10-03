<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Settings\Enums\AdvancePolicy;
use App\Models\User;
use App\Policies\PaymentPolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Money received from a member (BR-12): recorded by a maker as pending, approved by a different
 * checker, then allocated and posted. Never deleted; mistakes are rejected, cancelled or reversed.
 *
 * @property int $id
 * @property int $member_id
 * @property PaymentMethod $method
 * @property Money $amount_poisha
 * @property string|null $trx_id
 * @property string|null $proof_path
 * @property CarbonImmutable $received_on
 * @property PaymentStatus $status
 * @property string $idempotency_key
 * @property AdvancePolicy|null $advance_policy_at_payment
 * @property string|null $notes
 * @property int $recorded_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $rejected_by
 * @property string|null $rejection_reason
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 * @property int|null $journal_entry_id
 * @property int|null $reversal_journal_entry_id
 * @property-read Member $member
 * @property-read User $recorder
 * @property-read User|null $approver
 * @property-read JournalEntry|null $journalEntry
 */
#[UsePolicy(PaymentPolicy::class)]
final class Payment extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    /**
     * @return HasMany<AdvanceLedgerEntry, $this>
     */
    public function advanceEntries(): HasMany
    {
        return $this->hasMany(AdvanceLedgerEntry::class)->orderBy('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at', 'idempotency_key'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('payments');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount_poisha' => MoneyCast::class,
            'received_on' => 'immutable_date',
            'status' => PaymentStatus::class,
            'advance_policy_at_payment' => AdvancePolicy::class,
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }
}
