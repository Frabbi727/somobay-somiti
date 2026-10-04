<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Models;

use App\Domain\Integrity\Enums\IntegrityRunStatus;
use App\Models\User;
use App\Policies\IntegrityRunPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One execution of the §6.7 checks.
 *
 * @property int $id
 * @property IntegrityRunStatus $status
 * @property int $checks_run
 * @property int $findings_count
 * @property int|null $triggered_by
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[UsePolicy(IntegrityRunPolicy::class)]
final class IntegrityRun extends Model
{
    use LogsActivity;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * The most recent finished run, which decides whether the red banner shows.
     */
    public static function latestFinished(): ?self
    {
        return self::query()->where('status', '!=', IntegrityRunStatus::Running)->orderByDesc('id')->first();
    }

    /**
     * @return HasMany<IntegrityFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(IntegrityFinding::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function failed(): bool
    {
        return $this->status === IntegrityRunStatus::Failed;
    }

    protected function casts(): array
    {
        return [
            'status' => IntegrityRunStatus::class,
            'checks_run' => 'integer',
            'findings_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('integrity');
    }
}
