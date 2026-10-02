<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\PeriodStatus;
use App\Models\User;
use App\Policies\PeriodPolicy;
use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One month of a fiscal year. A locked period rejects new postings (BR-19).
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property int $sequence
 * @property YearMonth $month
 * @property PeriodStatus $status
 * @property CarbonImmutable|null $locked_at
 * @property int|null $locked_by
 * @property-read FiscalYear $fiscalYear
 */
#[UsePolicy(PeriodPolicy::class)]
final class Period extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('accounting');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'month' => YearMonthCast::class,
            'status' => PeriodStatus::class,
            'locked_at' => 'immutable_datetime',
        ];
    }
}
