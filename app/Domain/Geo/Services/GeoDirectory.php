<?php

declare(strict_types=1);

namespace App\Domain\Geo\Services;

use App\Domain\Geo\Models\GeoDistrict;
use App\Domain\Geo\Models\GeoDivision;
use App\Domain\Geo\Models\GeoUnion;
use App\Domain\Geo\Models\GeoUpazila;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class GeoDirectory
{
    /**
     * @return array<int, string>
     */
    public function divisions(?string $search = null): array
    {
        return $this->options(GeoDivision::query(), $search);
    }

    /**
     * @return array<int, string>
     */
    public function districts(int $divisionId, ?string $search = null): array
    {
        return $this->options(GeoDistrict::query()->where('division_id', $divisionId), $search);
    }

    /**
     * @return array<int, string>
     */
    public function upazilas(int $districtId, ?string $search = null): array
    {
        return $this->options(GeoUpazila::query()->where('district_id', $districtId), $search);
    }

    /**
     * @return array<int, string>
     */
    public function unions(int $upazilaId, ?string $search = null): array
    {
        return $this->options(GeoUnion::query()->where('upazila_id', $upazilaId), $search);
    }

    public function upazilaHasUnions(int $upazilaId): bool
    {
        return GeoUnion::query()->where('upazila_id', $upazilaId)->exists();
    }

    public function unionBelongsTo(int $unionId, int $upazilaId): bool
    {
        return GeoUnion::query()->whereKey($unionId)->where('upazila_id', $upazilaId)->exists();
    }

    /**
     * The place name from the smallest part to the largest, e.g. "union, upazila, district, division".
     */
    public function describe(int $upazilaId, ?int $unionId): string
    {
        $upazila = GeoUpazila::query()->findOrFail($upazilaId);
        $district = GeoDistrict::query()->findOrFail($upazila->district_id);
        $division = GeoDivision::query()->findOrFail($district->division_id);
        $parts = [];

        if ($unionId !== null) {
            $parts[] = $this->label(GeoUnion::query()->findOrFail($unionId));
        }

        $parts[] = $this->label($upazila);
        $parts[] = $this->label($district);
        $parts[] = $this->label($division);

        return implode(', ', $parts);
    }

    /**
     * @return array{division_id: int, district_id: int}
     */
    public function chainFor(int $upazilaId): array
    {
        $upazila = GeoUpazila::query()->findOrFail($upazilaId);
        $district = GeoDistrict::query()->findOrFail($upazila->district_id);

        return ['division_id' => $district->division_id, 'district_id' => $district->id];
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return array<int, string>
     */
    private function options(Builder $query, ?string $search): array
    {
        $term = trim((string) $search);

        if ($term !== '') {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('name_en', 'ILIKE', $like)->orWhere('name_bn', 'ILIKE', $like);
            });
        }

        $column = $this->column();
        $options = [];

        foreach ($query->orderBy($column)->get(['id', 'name_en', 'name_bn']) as $place) {
            $options[(int) $place->getKey()] = $this->label($place);
        }

        return $options;
    }

    private function label(Model $place): string
    {
        return (string) $place->getAttribute($this->column());
    }

    private function column(): string
    {
        return app()->getLocale() === 'bn' ? 'name_bn' : 'name_en';
    }
}
