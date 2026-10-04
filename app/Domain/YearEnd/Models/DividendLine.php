<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Models;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Members\Models\Member;
use App\Domain\YearEnd\Enums\DividendStatus;
use App\Policies\DividendLinePolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One member's dividend for a year (BR-21). Fixed once created; settled once, by payout or by
 * crediting the member's savings.
 *
 * @property int $id
 * @property int $year_end_id
 * @property int $member_id
 * @property int $share_months
 * @property Money $amount_poisha
 * @property DividendStatus $status
 * @property string|null $settled_via
 * @property int|null $settlement_journal_entry_id
 * @property int|null $settled_by
 * @property CarbonImmutable|null $settled_at
 * @property-read YearEnd $yearEnd
 * @property-read Member $member
 */
#[UsePolicy(DividendLinePolicy::class)]
final class DividendLine extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return BelongsTo<YearEnd, $this>
     */
    public function yearEnd(): BelongsTo
    {
        return $this->belongsTo(YearEnd::class);
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
    public function settlementEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'settlement_journal_entry_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'settled_via'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('year_end');
    }

    protected function casts(): array
    {
        return [
            'status' => DividendStatus::class,
            'amount_poisha' => MoneyCast::class,
            'share_months' => 'integer',
            'settled_at' => 'immutable_datetime',
        ];
    }
}
