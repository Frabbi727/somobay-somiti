<?php

declare(strict_types=1);

use App\Domain\Geo\Models\GeoDistrict;
use Illuminate\Support\Facades\Route;

/*
| The public place lookups behind the registration address dropdowns: both names per place,
| each list filtered by its parent, searched by a Bangla or English fragment.
*/

it('lists divisions with both names', function (): void {
    $response = $this->getJson('/api/v1/geo/divisions')->assertOk()->assertJsonCount(8, 'data');

    expect($response->json('data.0'))->toHaveKeys(['id', 'name_bn', 'name_en']);
});

it('filters districts by division', function (): void {
    $response = $this->getJson('/api/v1/geo/districts?division_id=6')->assertOk();

    expect($response->json('data'))->not->toBeEmpty()
        ->and(GeoDistrict::query()->whereIn('id', array_column($response->json('data'), 'id'))->where('division_id', '!=', 6)->exists())->toBeFalse();
});

it('geo search matches bangla and english prefixes', function (string $term): void {
    $ids = array_column($this->getJson('/api/v1/geo/unions?upazila_id=495&q='.urlencode($term))->assertOk()->json('data'), 'id');

    expect($ids)->toContain(4541, 4542);
})->with(['গুলশান', 'gulshan']);

it('requires the parent id', function (string $path): void {
    $this->getJson($path)->assertStatus(422)->assertJsonPath('success', false)->assertJsonStructure(['errors']);
})->with(['/api/v1/geo/districts', '/api/v1/geo/upazilas', '/api/v1/geo/unions']);

it('rejects a parent id that does not exist', function (): void {
    $this->getJson('/api/v1/geo/upazilas?district_id=999999')->assertStatus(422)->assertJsonStructure(['errors' => ['district_id']]);
});

it('caps the search term on the public lookups', function (): void {
    $this->getJson('/api/v1/geo/divisions?q='.str_repeat('a', 101))->assertStatus(422)->assertJsonStructure(['errors' => ['q']]);
    $this->getJson('/api/v1/geo/divisions?q='.str_repeat('a', 100))->assertOk();
});

it('gives the geo lookups their own per-IP limit, higher than the member api', function (): void {
    foreach (range(1, 61) as $call) {
        $this->getJson('/api/v1/geo/divisions')->assertOk();
    }

    expect(collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/geo/'))
        ->every(fn ($route): bool => in_array('throttle:geo', $route->gatherMiddleware(), true) && ! in_array('throttle:member-api', $route->gatherMiddleware(), true)))->toBeTrue();

    foreach (range(62, 300) as $call) {
        $this->getJson('/api/v1/geo/divisions');
    }

    $this->getJson('/api/v1/geo/divisions')->assertStatus(429);
});
