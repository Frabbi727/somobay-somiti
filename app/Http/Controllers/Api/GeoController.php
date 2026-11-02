<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Geo\Services\GeoDirectory;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Place lookups for the registration address dropdowns (no sign-in needed).
 */
final class GeoController
{
    public function __construct(private readonly GeoDirectory $geo) {}

    public function divisions(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->geo->places('division', null, $this->search($request)));
    }

    public function districts(Request $request): JsonResponse
    {
        $id = $this->parentId($request, 'division_id', 'geo_divisions');

        return ApiResponse::ok($this->geo->places('district', $id, $this->search($request)));
    }

    public function upazilas(Request $request): JsonResponse
    {
        $id = $this->parentId($request, 'district_id', 'geo_districts');

        return ApiResponse::ok($this->geo->places('upazila', $id, $this->search($request)));
    }

    public function unions(Request $request): JsonResponse
    {
        $id = $this->parentId($request, 'upazila_id', 'geo_upazilas');

        return ApiResponse::ok($this->geo->places('union', $id, $this->search($request)));
    }

    private function parentId(Request $request, string $field, string $table): int
    {
        $validated = $request->validate([$field => ['required', 'integer', Rule::exists($table, 'id')]]);

        return (int) $validated[$field];
    }

    private function search(Request $request): ?string
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return $validated['q'] ?? null;
    }
}
