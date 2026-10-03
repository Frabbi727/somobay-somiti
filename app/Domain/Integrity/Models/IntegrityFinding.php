<?php

declare(strict_types=1);

namespace App\Domain\Integrity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $integrity_run_id
 * @property string $check
 * @property string $message
 * @property array<string, int|string|null>|null $context
 */
final class IntegrityFinding extends Model
{
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
}
