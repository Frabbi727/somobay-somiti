<?php

declare(strict_types=1);

use App\Domain\Geo\Models\GeoDistrict;
use App\Domain\Geo\Models\GeoDivision;
use App\Domain\Geo\Models\GeoUnion;
use App\Domain\Geo\Models\GeoUpazila;
use App\Domain\Geo\Services\GeoCsvLoader;
use App\Domain\Geo\Services\GeoDirectory;

test('loads every place from the csv files', function (): void {
    expect(GeoDivision::count())->toBe(8)
        ->and(GeoDistrict::count())->toBe(64)
        ->and(GeoUpazila::count())->toBe(576)
        ->and(GeoUnion::count())->toBe(4855)
        ->and(GeoUnion::find(4541)?->name_bn)->toBe('গুলশান-১');
});

test('reloading is idempotent', function (): void {
    app(GeoCsvLoader::class)->load(database_path('demrcation'));

    expect(GeoDivision::count())->toBe(8)
        ->and(GeoDistrict::count())->toBe(64)
        ->and(GeoUpazila::count())->toBe(576)
        ->and(GeoUnion::count())->toBe(4855);
});

test('lists children of a parent only', function (): void {
    $districts = app(GeoDirectory::class)->districts(6);

    expect($districts)->toHaveKey(47)
        ->and(GeoDistrict::whereIn('id', array_keys($districts))->where('division_id', '!=', 6)->count())->toBe(0);
});

test('knows upazilas without unions', function (): void {
    $directory = app(GeoDirectory::class);
    $guimara = GeoUpazila::where('name_en', 'Guimara')->firstOrFail();

    expect($directory->upazilaHasUnions($guimara->id))->toBeFalse()
        ->and($directory->upazilaHasUnions(365))->toBeTrue();
});

test('describe builds the full place name', function (): void {
    $directory = app(GeoDirectory::class);
    $union = GeoUnion::findOrFail(4541);
    $upazilaId = $union->upazila_id;

    app()->setLocale('en');
    $full = $directory->describe($upazilaId, 4541);
    expect($full)->toStartWith($union->name_en.', ')
        ->and(substr_count($full, ', '))->toBe(3)
        ->and($directory->describe($upazilaId, null))->not->toContain($union->name_en.', ');

    app()->setLocale('bn');
    expect($directory->describe($upazilaId, 4541))->toStartWith('গুলশান-১, ');
});

test('chain, membership and search work', function (): void {
    $directory = app(GeoDirectory::class);
    $union = GeoUnion::findOrFail(4541);
    $chain = $directory->chainFor($union->upazila_id);

    expect($chain['district_id'])->toBe($union->upazila->district_id)
        ->and($directory->unionBelongsTo(4541, $union->upazila_id))->toBeTrue()
        ->and($directory->unionBelongsTo(4541, 365))->toBeFalse();

    app()->setLocale('en');
    expect($directory->divisions('dhak'))->toHaveCount(1)
        ->and($directory->divisions('ঢাকা'))->toHaveCount(1)
        ->and($directory->divisions('100%'))->toBe([]);
});
