<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Domain\Accounting\Enums\FiscalYearStatus;
use App\Models\User;
use App\Policies\FiscalYearPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $start_year
 * @property string $code
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property FiscalYearStatus $status
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 */
#[UsePolicy(FiscalYearPolicy::class)]
final class FiscalYear extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return HasMany<Period, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(Period::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === FiscalYearStatus::Open;
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
            'start_year' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'status' => FiscalYearStatus::class,
            'closed_at' => 'immutable_datetime',
        ];
    }
}
