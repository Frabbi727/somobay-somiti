<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reference data loaded from the CSV files; ids are the CSV ids.
 *
 * @property int $id
 * @property string $name_en
 * @property string $name_bn
 */
final class GeoDivision extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'geo_divisions';

    protected $guarded = [];

    /**
     * @return HasMany<GeoDistrict, $this>
     */
    public function districts(): HasMany
    {
        return $this->hasMany(GeoDistrict::class, 'division_id');
    }
}
