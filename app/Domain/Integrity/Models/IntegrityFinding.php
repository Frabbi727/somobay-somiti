<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Models;

use App\Policies\IntegrityFindingPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $integrity_run_id
 * @property string $check
 * @property string $message
 * @property array<string, int|string|null>|null $context
 */
#[UsePolicy(IntegrityFindingPolicy::class)]
final class IntegrityFinding extends Model
{
    use LogsActivity;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<IntegrityRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(IntegrityRun::class, 'integrity_run_id');
    }

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('integrity');
    }
}
