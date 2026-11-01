<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reference data loaded from the CSV files; ids are the CSV ids.
 *
 * @property int $id
 * @property int $district_id
 * @property string $name_en
 * @property string $name_bn
 */
final class GeoUpazila extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'geo_upazilas';

    protected $guarded = [];

    /**
     * @return BelongsTo<GeoDistrict, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(GeoDistrict::class, 'district_id');
    }

    /**
     * @return HasMany<GeoUnion, $this>
     */
    public function unions(): HasMany
    {
        return $this->hasMany(GeoUnion::class, 'upazila_id');
    }
}
