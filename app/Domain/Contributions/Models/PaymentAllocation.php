<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Models;

use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of an approved payment applied to one due. Counts only while the payment is approved.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $due_id
 * @property Money $amount_poisha
 * @property-read Due $due
 * @property-read Payment $payment
 */
final class PaymentAllocation extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * An allocation is always shown with its due.
     *
     * @var list<string>
     */
    protected $with = ['due'];

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
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_poisha' => MoneyCast::class];
    }
}
