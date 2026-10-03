<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Models\User;
use App\Policies\JournalEntryPolicy;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A posted voucher. Append-only: once inserted it is never updated or deleted (BR-17);
 * mistakes are corrected by a reversing entry whose reverses_id points here.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property int $period_id
 * @property VoucherType $voucher_type
 * @property string $voucher_no
 * @property CarbonImmutable $entry_date
 * @property string $narration
 * @property string|null $reason
 * @property int|null $reverses_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int $posted_by
 * @property CarbonImmutable $posted_at
 * @property string|null $hash
 * @property-read FiscalYear $fiscalYear
 * @property-read Period $period
 * @property-read JournalEntry|null $reverses
 * @property-read JournalEntry|null $reversal
 * @property-read User $postedBy
 */
#[UsePolicy(JournalEntryPolicy::class)]
final class JournalEntry extends Model
{
    use LogsActivity;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn (self $entry) => throw ImmutableRecord::for(self::class, $entry->getKey()));
        self::deleting(fn (self $entry) => throw ImmutableRecord::for(self::class, $entry->getKey()));
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * The entry this one reverses.
     *
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /**
     * The entry that reverses this one, if any.
     *
     * @return HasOne<JournalEntry, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether a business record (payment, refund, advance application, expense…) owns this voucher.
     * Vouchers from journal drafts — and reversals, which point at their original — are not owned.
     */
    public function isOwnedByRecord(): bool
    {
        return $this->source_type !== null
            && ! in_array($this->source_type, [(new JournalDraft)->getMorphClass(), $this->getMorphClass()], true);
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }

    /**
     * Uses a preloaded withExists('reversal') value when present to avoid a query per row.
     */
    public function isReversed(): bool
    {
        $preloaded = $this->getAttribute('reversal_exists');

        if ($preloaded !== null) {
            return (bool) $preloaded;
        }

        return $this->relationLoaded('reversal')
            ? $this->reversal !== null
            : $this->reversal()->exists();
    }

    /**
     * Total of the debit side (equal to the credit side for every posted entry).
     */
    public function amount(): Money
    {
        $preloaded = $this->getAttribute('lines_sum_debit_poisha');

        if ($preloaded !== null) {
            return Money::ofPoisha((int) $preloaded);
        }

        return Money::ofPoisha((int) $this->lines()->sum('debit_poisha'));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['voucher_no', 'voucher_type', 'entry_date', 'narration', 'reason', 'reverses_id'])
            ->useLogName('accounting');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voucher_type' => VoucherType::class,
            'entry_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
        ];
    }
}
