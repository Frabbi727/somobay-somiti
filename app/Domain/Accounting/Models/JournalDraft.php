<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\VoucherType;
use App\Models\User;
use App\Policies\JournalDraftPolicy;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A manual voucher being prepared. It may be incomplete or unbalanced until it is posted,
 * at which point PostJournal validates it and it becomes read-only.
 *
 * @property int $id
 * @property VoucherType $voucher_type
 * @property CarbonImmutable $entry_date
 * @property string $narration
 * @property list<array{account_id: int, member_id: int|null, debit_poisha: int, credit_poisha: int, memo: string|null}> $lines
 * @property int $created_by
 * @property int|null $journal_entry_id
 * @property-read User $creator
 * @property-read JournalEntry|null $journalEntry
 */
#[UsePolicy(JournalDraftPolicy::class)]
final class JournalDraft extends Model
{
    use LogsActivity, SoftDeletes;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isPosted(): bool
    {
        return $this->journal_entry_id !== null;
    }

    public function totalDebit(): Money
    {
        return Money::ofPoisha(array_sum(array_column($this->lines, 'debit_poisha')));
    }

    public function totalCredit(): Money
    {
        return Money::ofPoisha(array_sum(array_column($this->lines, 'credit_poisha')));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('accounting');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voucher_type' => VoucherType::class,
            'entry_date' => 'immutable_date',
            'lines' => 'array',
        ];
    }
}
