<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The portal's Dues page: open dues by default (status=all for every due), filterable by type.
 */
final class DuesController
{
    use ResolvesMember;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', ...array_column(DueStatus::cases(), 'value')])],
            'type' => ['nullable', Rule::enum(DueType::class)],
        ]);
        $status = (string) ($filters['status'] ?? DueStatus::Open->value);
        $type = $filters['type'] ?? null;

        $page = Due::query()
            ->where('member_id', self::member($request)->id)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when(is_string($type), fn ($query) => $query->where('type', $type))
            ->orderByDesc('month')->orderBy('id')
            ->paginate(ApiResponse::PER_PAGE);

        return ApiResponse::paginated($page, fn (Due $due): array => [
            'id' => $due->id,
            'month' => ApiValue::month($due->month),
            'type' => ApiValue::enum($due->type),
            'amount' => ApiValue::money($due->amount_poisha),
            'paid' => ApiValue::money($due->paid_poisha),
            'outstanding' => ApiValue::money($due->outstanding_poisha),
            'due_date' => ApiValue::date($due->due_date),
            'status' => ApiValue::enum($due->status),
        ]);
    }
}
