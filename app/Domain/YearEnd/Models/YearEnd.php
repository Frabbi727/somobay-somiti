<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Models;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Governance\Models\Resolution;
use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Models\User;
use App\Policies\YearEndPolicy;
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
 * The year-end closing of one fiscal year (W8): a draft snapshot of the figures, approved by the
 * president and the accountant, then posted. Posted year-ends are permanent.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property YearEndStatus $status
 * @property Money $net_profit_poisha
 * @property Money $prior_loss_poisha
 * @property Money $loss_offset_poisha
 * @property int $reserve_bps
 * @property int $development_fund_bps
 * @property int $bad_debt_fund_bps
 * @property int $other_funds_bps
 * @property array<string, int> $appropriation
 * @property Money $dividend_pool_poisha
 * @property int $total_share_months
 * @property string $fingerprint
 * @property int|null $resolution_id
 * @property int $prepared_by
 * @property int|null $president_approved_by
 * @property CarbonImmutable|null $president_approved_at
 * @property int|null $accountant_approved_by
 * @property CarbonImmutable|null $accountant_approved_at
 * @property int|null $closing_journal_entry_id
 * @property int|null $appropriation_journal_entry_id
 * @property int|null $posted_by
 * @property CarbonImmutable|null $posted_at
 * @property-read FiscalYear $fiscalYear
 * @property-read Resolution|null $resolution
 */
#[UsePolicy(YearEndPolicy::class)]
final class YearEnd extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * Always shown with its year (code in titles and confirmations).
     *
     * @var list<string>
     */
    protected $with = ['fiscalYear'];

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return HasMany<DividendLine, $this>
     */
    public function dividendLines(): HasMany
    {
        return $this->hasMany(DividendLine::class);
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
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function closingEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'closing_journal_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function appropriationEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'appropriation_journal_entry_id');
    }

    public function isFullyApproved(): bool
    {
        return $this->president_approved_by !== null && $this->accountant_approved_by !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('year_end');
    }

    protected function casts(): array
    {
        return [
            'status' => YearEndStatus::class,
            'net_profit_poisha' => MoneyCast::class,
            'prior_loss_poisha' => MoneyCast::class,
            'loss_offset_poisha' => MoneyCast::class,
            'dividend_pool_poisha' => MoneyCast::class,
            'appropriation' => 'array',
            'reserve_bps' => 'integer',
            'development_fund_bps' => 'integer',
            'bad_debt_fund_bps' => 'integer',
            'other_funds_bps' => 'integer',
            'total_share_months' => 'integer',
            'president_approved_at' => 'immutable_datetime',
            'accountant_approved_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
        ];
    }
}
