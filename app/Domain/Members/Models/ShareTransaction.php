<?php

declare(strict_types=1);

namespace App\Domain\Members\Models;

use App\Domain\Members\Enums\ShareChangeType;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Models\User;
use App\Policies\ShareTransactionPolicy;
use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Append-only log of share changes.
 *
 * @property int $id
 * @property int $member_id
 * @property ShareChangeType $type
 * @property int $shares
 * @property int $shares_after
 * @property YearMonth $effective_from
 * @property string|null $reason
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read User $creator
 */
#[UsePolicy(ShareTransactionPolicy::class)]
final class ShareTransaction extends Model
{
    use LogsActivity;

    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn (self $transaction) => throw ImmutableRecord::for(self::class, $transaction->getKey()));
        self::deleting(fn (self $transaction) => throw ImmutableRecord::for(self::class, $transaction->getKey()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ShareChangeType::class,
            'shares' => 'integer',
            'shares_after' => 'integer',
            'effective_from' => YearMonthCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('members');
    }
}
