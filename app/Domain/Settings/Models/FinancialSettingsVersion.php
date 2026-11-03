<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Data\FinancialSettingsData;
use App\Domain\Settings\Enums\FinancialSettingsStatus;
use App\Models\User;
use App\Policies\FinancialSettingsVersionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One proposed set of financial settings; the newest approved one is in force (T-19).
 *
 * @property int $id
 * @property array<string, mixed> $settings
 * @property string $reason
 * @property FinancialSettingsStatus $status
 * @property string $idempotency_key
 * @property int $proposed_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $rejection_reason
 * @property CarbonImmutable $created_at
 * @property-read User $proposer
 * @property-read User|null $decider
 */
#[UsePolicy(FinancialSettingsVersionPolicy::class)]
final class FinancialSettingsVersion extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function values(): FinancialSettingsData
    {
        return FinancialSettingsData::fromStored($this->settings);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'status' => FinancialSettingsStatus::class,
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('settings');
    }
}
