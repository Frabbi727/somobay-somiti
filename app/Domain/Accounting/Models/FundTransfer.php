<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Models\User;
use App\Policies\FundTransferPolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Money moved between the somiti's own cash, bank and wallets (a bank deposit, a cash-out).
 *
 * @property int $id
 * @property string $transfer_no
 * @property PaymentMethod $from_method
 * @property PaymentMethod $to_method
 * @property Money $amount_poisha
 * @property Money $charge_poisha
 * @property CarbonImmutable $transferred_on
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $attachment_path
 * @property TransferStatus $status
 * @property string $idempotency_key
 * @property int $recorded_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $rejected_by
 * @property string|null $rejection_reason
 * @property int|null $reversed_by
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversal_reason
 * @property int|null $journal_entry_id
 * @property int|null $reversal_journal_entry_id
 * @property-read User $recorder
 * @property-read User|null $approver
 * @property-read JournalEntry|null $journalEntry
 */
#[UsePolicy(FundTransferPolicy::class)]
final class FundTransfer extends Model
{
    use LogsActivity;

    protected $guarded = [];

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
     * What leaves the source account: the amount plus any charge.
     */
    public function outflow(): Money
    {
        return $this->amount_poisha->plus($this->charge_poisha);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('transfers');
    }

    protected function casts(): array
    {
        return [
            'from_method' => PaymentMethod::class,
            'to_method' => PaymentMethod::class,
            'amount_poisha' => MoneyCast::class,
            'charge_poisha' => MoneyCast::class,
            'transferred_on' => 'immutable_date',
            'status' => TransferStatus::class,
            'approved_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }
}
