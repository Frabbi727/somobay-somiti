<?php

declare(strict_types=1);

namespace App\Domain\Members\Models;

use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Illuminate\Database\Eloquent\Model;

/**
 * The share timeline: a member holds `shares` from effective_from until the next row. Rebuilt
 * from the share lots after every change, and read by dues, dividends and previews.
 *
 * @property int $id
 * @property int $member_id
 * @property YearMonth $effective_from
 * @property int $shares
 */
final class MemberShareSnapshot extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['effective_from' => YearMonthCast::class, 'shares' => 'integer'];
    }
}
