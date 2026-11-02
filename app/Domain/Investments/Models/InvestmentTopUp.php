<?php

declare(strict_types=1);

namespace App\Domain\Investments\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Models\User;
use App\Policies\InvestmentTopUpPolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * More money put into an active investment. Pending until someone other than the recorder approves
 * it; approval pays it out by voucher and adds a "top_up" entry to the investment's register.
 *
 * @property int $id
 * @property int $investment_id
 * @property Money $amount_poisha
 * @property PaymentMethod $funded_from
 * @property CarbonImmutable $invested_on
 * @property string|null $notes
 * @property int|null $resolution_id
 * @property string|null $limit_warnings
 * @property InvestmentTopUpStatus $status
 * @property string $idempotency_key
 * @property int $recorded_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $rejected_by
 * @property string|null $rejection_reason
 * @property int|null $journal_entry_id
 * @property CarbonImmutable $created_at
 * @property-read Investment $investment
 * @property-read Resolution|null $resolution
 * @property-read User $recorder
 * @property-read User|null $approver
 * @property-read JournalEntry|null $journalEntry
 */
#[UsePolicy(InvestmentTopUpPolicy::class)]
final class InvestmentTopUp extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return BelongsTo<Investment, $this>
     */
    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    /**
     * @return BelongsTo<Resolution, $this>
     */
    public function resolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class);
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('investments');
    }

    protected function casts(): array
    {
        return [
            'status' => InvestmentTopUpStatus::class,
            'funded_from' => PaymentMethod::class,
            'amount_poisha' => MoneyCast::class,
            'invested_on' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
