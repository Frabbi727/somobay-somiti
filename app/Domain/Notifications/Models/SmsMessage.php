<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use App\Domain\Members\Models\Member;
use App\Domain\Notifications\Enums\SmsStatus;
use App\Policies\SmsMessagePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $to
 * @property int|null $member_id
 * @property string|null $template_key
 * @property string|null $dedupe_key
 * @property string $body
 * @property int $segments
 * @property SmsStatus $status
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property int $attempts
 * @property CarbonImmutable|null $sent_at
 * @property-read Member|null $member
 */
#[UsePolicy(SmsMessagePolicy::class)]
final class SmsMessage extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => SmsStatus::class, 'segments' => 'integer', 'attempts' => 'integer', 'sent_at' => 'immutable_datetime'];
    }

    /**
     * SMS bodies can hold sign-in codes, so they stay out of the audit log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at', 'body'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('messaging');
    }
}
