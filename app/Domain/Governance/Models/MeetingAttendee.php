<?php

declare(strict_types=1);

namespace App\Domain\Governance\Models;

use App\Domain\Members\Models\Member;
use App\Models\User;
use App\Policies\MeetingAttendeePolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A member (general meetings) or committee user (committee meetings) present at a meeting.
 *
 * @property int $id
 * @property int $meeting_id
 * @property int|null $member_id
 * @property int|null $user_id
 * @property-read Member|null $member
 * @property-read User|null $user
 */
#[UsePolicy(MeetingAttendeePolicy::class)]
final class MeetingAttendee extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return BelongsTo<Meeting, $this>
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('governance');
    }
}
