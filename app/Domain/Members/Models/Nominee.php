<?php

declare(strict_types=1);

namespace App\Domain\Members\Models;

use App\Policies\NomineePolicy;
use App\Support\Money\Bps;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $member_id
 * @property string $name
 * @property string $relation
 * @property int|null $relation_id
 * @property string|null $mobile
 * @property string|null $nid
 * @property int $share_bps
 * @property int $sort
 * @property-read NomineeRelation|null $nomineeRelation
 */
#[UsePolicy(NomineePolicy::class)]
final class Nominee extends Model
{
    use LogsActivity, SoftDeletes;

    protected $guarded = [];

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<NomineeRelation, $this>
     */
    public function nomineeRelation(): BelongsTo
    {
        return $this->belongsTo(NomineeRelation::class, 'relation_id');
    }

    /**
     * The relation in the current language; older nominees show the text typed in at the time.
     */
    public function relationLabel(): string
    {
        return $this->nomineeRelation?->label() ?? $this->relation;
    }

    public function share(): Bps
    {
        return Bps::of($this->share_bps);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('members');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['share_bps' => 'integer', 'sort' => 'integer', 'relation_id' => 'integer'];
    }
}
