<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Models;

use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a member's advance (2111): positive = held, negative = applied or refunded.
 * balance_after is the running balance and can never go below zero.
 *
 * @property int $id
 * @property int $member_id
 * @property AdvanceEntryKind $kind
 * @property Money $delta_poisha
 * @property Money $balance_after_poisha
 * @property int|null $payment_id
 * @property int|null $due_id
 * @property int|null $journal_entry_id
 * @property int|null $reverses_entry_id
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Due|null $due
 */
final class AdvanceLedgerEntry extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn (self $row) => throw ImmutableRecord::for(self::class, $row->getKey()));
        self::deleting(fn (self $row) => throw ImmutableRecord::for(self::class, $row->getKey()));
    }

    /**
     * @return BelongsTo<Due, $this>
     */
    public function due(): BelongsTo
    {
        return $this->belongsTo(Due::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AdvanceEntryKind::class,
            'delta_poisha' => MoneyCast::class,
            'balance_after_poisha' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
