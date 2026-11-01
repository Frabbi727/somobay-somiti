<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reference data loaded from the CSV files; ids are the CSV ids.
 *
 * @property int $id
 * @property int $upazila_id
 * @property string $name_en
 * @property string $name_bn
 */
final class GeoUnion extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'geo_unions';

    protected $guarded = [];

    /**
     * @return BelongsTo<GeoUpazila, $this>
     */
    public function upazila(): BelongsTo
    {
        return $this->belongsTo(GeoUpazila::class, 'upazila_id');
    }
}
