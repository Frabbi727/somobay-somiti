<?php

declare(strict_types=1);

namespace App\Domain\Investments\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentType;
use App\Models\User;
use App\Policies\InvestmentPolicy;
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
 * An investment of the somiti's funds (FDR, savings certificate, securities, …). Its book value is
 * the sum of its ledger entries and ties to its 13xx account.
 *
 * @property int $id
 * @property string $investment_no
 * @property InvestmentType $type
 * @property int $account_id
 * @property string $institution
 * @property string|null $instrument_no
 * @property Money $principal_poisha
 * @property PaymentMethod $funded_from
 * @property CarbonImmutable $invested_on
 * @property CarbonImmutable|null $matures_on
 * @property int|null $expected_rate_bps
 * @property string|null $notes
 * @property string|null $attachment_path
 * @property int|null $resolution_id
 * @property string|null $limit_warnings
 * @property InvestmentStatus $status
 * @property string $idempotency_key
 * @property int $recorded_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $rejected_by
 * @property string|null $rejection_reason
 * @property int|null $journal_entry_id
 * @property CarbonImmutable|null $closed_on
 * @property-read Account $account
 * @property-read Resolution|null $resolution
 * @property-read User $recorder
 * @property-read User|null $approver
 * @property-read JournalEntry|null $journalEntry
 */
#[UsePolicy(InvestmentPolicy::class)]
final class Investment extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return HasMany<InvestmentLedgerEntry, $this>
     */
    public function ledger(): HasMany
    {
        return $this->hasMany(InvestmentLedgerEntry::class);
    }

    /**
     * @return HasMany<InvestmentIncome, $this>
     */
    public function income(): HasMany
    {
        return $this->hasMany(InvestmentIncome::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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

    /**
     * Principal less impairments, until it is closed.
     */
    public function bookValue(): Money
    {
        return Money::ofPoisha((int) $this->ledger()->sum('delta_poisha'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('investments');
    }

    protected function casts(): array
    {
        return [
            'type' => InvestmentType::class,
            'status' => InvestmentStatus::class,
            'funded_from' => PaymentMethod::class,
            'principal_poisha' => MoneyCast::class,
            'invested_on' => 'immutable_date',
            'matures_on' => 'immutable_date',
            'closed_on' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'expected_rate_bps' => 'integer',
        ];
    }
}
