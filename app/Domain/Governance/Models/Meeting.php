<?php

declare(strict_types=1);

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Enums\MeetingType;
use App\Models\User;
use App\Policies\MeetingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A committee or general meeting (সভা). Once held, its attendance and quorum are a fixed record.
 *
 * @property int $id
 * @property string $meeting_no
 * @property MeetingType $type
 * @property string $title
 * @property CarbonImmutable $scheduled_at
 * @property string|null $venue
 * @property string|null $agenda
 * @property MeetingStatus $status
 * @property int|null $eligible_count
 * @property int|null $attendees_count
 * @property int|null $quorum_required
 * @property bool|null $quorum_met
 * @property string|null $minutes
 * @property CarbonImmutable|null $held_at
 * @property string|null $cancel_reason
 * @property int $created_by
 * @property int|null $held_recorded_by
 */
#[UsePolicy(MeetingPolicy::class)]
final class Meeting extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return HasMany<MeetingAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    /**
     * @return HasMany<Resolution, $this>
     */
    public function resolutions(): HasMany
    {
        return $this->hasMany(Resolution::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('governance');
    }

    protected function casts(): array
    {
        return [
            'type' => MeetingType::class,
            'status' => MeetingStatus::class,
            'scheduled_at' => 'immutable_datetime',
            'held_at' => 'immutable_datetime',
            'eligible_count' => 'integer',
            'attendees_count' => 'integer',
            'quorum_required' => 'integer',
            'quorum_met' => 'boolean',
        ];
    }
}
