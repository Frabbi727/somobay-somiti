<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * The envelope the Flutter app's ApiResponse<T> reads: success, statusCode, message, data, errors, meta.
 */
final class ApiResponse
{
    public const int PER_PAGE = 20;

    public static function ok(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return self::make(true, $status, $message ?? __('api.ok'), $data);
    }

    /**
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $page
     * @param  callable(TItem): mixed  $map
     */
    public static function paginated(LengthAwarePaginator $page, callable $map): JsonResponse
    {
        return self::make(true, 200, __('api.ok'), array_map($map, $page->items()), null, [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     */
    public static function error(string $message, int $status, ?array $errors = null): JsonResponse
    {
        return self::make(false, $status, $message, null, $errors);
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, int>|null  $meta
     */
    private static function make(bool $success, int $status, string $message, mixed $data, ?array $errors = null, ?array $meta = null): JsonResponse
    {
        return new JsonResponse([
            'success' => $success,
            'statusCode' => $status,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta,
        ], $status);
    }
}
