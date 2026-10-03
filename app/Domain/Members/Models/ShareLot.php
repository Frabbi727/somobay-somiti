<?php

declare(strict_types=1);

namespace App\Domain\Members\Models;

use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shares acquired together from one month (BR-1). A lot never changes size; a decrease ends it
 * (ended_from) and, if only part of it goes, a continuation lot carries the rest.
 *
 * @property int $id
 * @property int $member_id
 * @property int $shares
 * @property YearMonth $effective_from
 * @property YearMonth|null $ended_from
 * @property int|null $continues_lot_id
 * @property int $created_by
 * @property-read Member $member
 */
final class ShareLot extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (self $lot): void {
            $allowed = $lot->getRawOriginal('ended_from') === null
                && array_diff(array_keys($lot->getDirty()), ['ended_from', 'updated_at']) === [];

            if (! $allowed) {
                throw ImmutableRecord::for(self::class, $lot->getKey());
            }
        });

        self::deleting(fn (self $lot) => throw ImmutableRecord::for(self::class, $lot->getKey()));
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function isActiveIn(YearMonth $month): bool
    {
        return $this->effective_from->isSameOrBefore($month)
            && ($this->ended_from === null || $this->ended_from->isAfter($month));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shares' => 'integer',
            'effective_from' => YearMonthCast::class,
            'ended_from' => YearMonthCast::class,
        ];
    }
}
