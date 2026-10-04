<?php

declare(strict_types=1);

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Enums\Majority;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Policies\ResolutionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A resolution (প্রস্তাব/সিদ্ধান্ত) put to a meeting. Decided ones are permanent records.
 *
 * @property int $id
 * @property string $resolution_no
 * @property int $meeting_id
 * @property ResolutionSubject $subject
 * @property string $title
 * @property string $body
 * @property Majority $majority
 * @property ResolutionStatus $status
 * @property int|null $votes_for
 * @property int|null $votes_against
 * @property int|null $votes_abstain
 * @property CarbonImmutable|null $decided_at
 * @property int $proposed_by
 * @property int|null $decided_by
 * @property-read Meeting $meeting
 */
#[UsePolicy(ResolutionPolicy::class)]
final class Resolution extends Model
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

    public function displayName(): string
    {
        return $this->resolution_no.' · '.$this->title;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('governance');
    }

    protected function casts(): array
    {
        return [
            'subject' => ResolutionSubject::class,
            'majority' => Majority::class,
            'status' => ResolutionStatus::class,
            'votes_for' => 'integer',
            'votes_against' => 'integer',
            'votes_abstain' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
