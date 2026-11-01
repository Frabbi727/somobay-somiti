<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use App\Policies\GeoDistrictPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reference data loaded from the CSV files; ids are the CSV ids.
 *
 * @property int $id
 * @property int $division_id
 * @property string $name_en
 * @property string $name_bn
 */
#[UsePolicy(GeoDistrictPolicy::class)]
final class GeoDistrict extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'geo_districts';

    protected $guarded = [];

    /**
     * @return BelongsTo<GeoDivision, $this>
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(GeoDivision::class, 'division_id');
    }

    /**
     * @return HasMany<GeoUpazila, $this>
     */
    public function upazilas(): HasMany
    {
        return $this->hasMany(GeoUpazila::class, 'district_id');
    }
}
