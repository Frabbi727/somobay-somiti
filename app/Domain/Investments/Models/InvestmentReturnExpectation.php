<?php

declare(strict_types=1);

namespace App\Domain\Investments\Models;

use App\Domain\Investments\Enums\ReturnFrequency;
use App\Models\User;
use App\Policies\InvestmentReturnExpectationPolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Append-only: what an investment is expected to pay from a date. Tracking only, never posted.
 *
 * @property int $id
 * @property int $investment_id
 * @property Money $amount_poisha
 * @property ReturnFrequency $frequency
 * @property CarbonImmutable $starts_on
 * @property string|null $notes
 * @property string $idempotency_key
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read User $creator
 */
#[UsePolicy(InvestmentReturnExpectationPolicy::class)]
final class InvestmentReturnExpectation extends Model
{
    use LogsActivity;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return BelongsTo<Investment, $this>
     */
    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'amount_poisha' => MoneyCast::class,
            'frequency' => ReturnFrequency::class,
            'starts_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('investments');
    }
}
