<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Models\User;
use App\Policies\ExpensePolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Money spent by the somiti, charged to an expense account (5xxx) and paid from cash, bank or a
 * wallet. Maker-checker like payments; corrections are rejections, cancellations or reversals.
 *
 * @property int $id
 * @property string $expense_no
 * @property int $account_id
 * @property PaymentMethod $paid_from
 * @property Money $amount_poisha
 * @property CarbonImmutable $spent_on
 * @property string|null $payee
 * @property string|null $reference
 * @property string $description
 * @property string|null $attachment_path
 * @property ExpenseStatus $status
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
 * @property-read Account $account
 * @property-read User $recorder
 * @property-read User|null $approver
 * @property-read JournalEntry|null $journalEntry
 * @property-read JournalEntry|null $reversalJournalEntry
 */
#[UsePolicy(ExpensePolicy::class)]
final class Expense extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    /**
     * Above the threshold only the president may approve (SOMITI_SPEC.md §1.3).
     */
    public function needsPresident(): bool
    {
        return $this->amount_poisha->poisha >= (int) config('somiti.expense_president_threshold_poisha');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('expenses');
    }

    protected function casts(): array
    {
        return [
            'paid_from' => PaymentMethod::class,
            'amount_poisha' => MoneyCast::class,
            'spent_on' => 'immutable_date',
            'status' => ExpenseStatus::class,
            'approved_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }
}
