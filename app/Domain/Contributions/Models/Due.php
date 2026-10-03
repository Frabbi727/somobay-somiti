<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Models;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\ShareLot;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Policies\DuePolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a member owes for a month (BR-8/9). The amount and the rate snapshot it was built
 * from never change; only payments and the status move. outstanding_poisha is computed by the database.
 *
 * @property int $id
 * @property int $member_id
 * @property YearMonth $month
 * @property DueType $type
 * @property int|null $share_lot_id
 * @property int $adjustment_seq
 * @property int $rate_plan_id
 * @property array<string, mixed> $snapshot
 * @property Money $amount_poisha
 * @property Money $paid_poisha
 * @property Money $outstanding_poisha
 * @property CarbonImmutable $due_date
 * @property int|null $parent_due_id
 * @property bool $prepaid_locked
 * @property DueStatus $status
 * @property string|null $note
 * @property-read Member $member
 * @property-read RatePlan $ratePlan
 */
#[UsePolicy(DuePolicy::class)]
final class Due extends Model
{
    /**
     * Columns that may change after creation (BR-9).
     */
    private const array MUTABLE = ['paid_poisha', 'status', 'note', 'closed_at', 'prepaid_locked', 'updated_at'];

    protected $guarded = ['outstanding_poisha'];

    protected static function booted(): void
    {
        self::updating(function (self $due): void {
            if (array_diff(array_keys($due->getDirty()), self::MUTABLE) !== []) {
                throw ImmutableRecord::for(self::class, $due->getKey());
            }
        });

        self::deleting(fn (self $due) => throw ImmutableRecord::for(self::class, $due->getKey()));
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<ShareLot, $this>
     */
    public function shareLot(): BelongsTo
    {
        return $this->belongsTo(ShareLot::class);
    }

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => YearMonthCast::class,
            'type' => DueType::class,
            'adjustment_seq' => 'integer',
            'snapshot' => 'array',
            'amount_poisha' => MoneyCast::class,
            'paid_poisha' => MoneyCast::class,
            'outstanding_poisha' => MoneyCast::class,
            'due_date' => 'immutable_date',
            'prepaid_locked' => 'boolean',
            'status' => DueStatus::class,
            'closed_at' => 'immutable_datetime',
        ];
    }
}
