<?php

declare(strict_types=1);

namespace App\Domain\Members\Models;

use App\Policies\MemberShareSnapshotPolicy;
use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The share timeline: a member holds `shares` from effective_from until the next row. Rebuilt
 * from the share lots after every change, and read by dues, dividends and previews.
 *
 * @property int $id
 * @property int $member_id
 * @property YearMonth $effective_from
 * @property int $shares
 */
#[UsePolicy(MemberShareSnapshotPolicy::class)]
final class MemberShareSnapshot extends Model
{
    use LogsActivity;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['effective_from' => YearMonthCast::class, 'shares' => 'integer'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('members');
    }
}
