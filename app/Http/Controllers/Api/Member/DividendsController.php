<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\YearEnd\Models\DividendLine;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The portal's Dividends page: one line per closed fiscal year.
 */
final class DividendsController
{
    use ResolvesMember;

    public function index(Request $request): JsonResponse
    {
        $page = DividendLine::query()
            ->where('member_id', self::member($request)->id)
            ->with('yearEnd.fiscalYear')
            ->orderByDesc('id')
            ->paginate(ApiResponse::PER_PAGE);

        return ApiResponse::paginated($page, fn (DividendLine $line): array => [
            'id' => $line->id,
            'fiscal_year' => $line->yearEnd->fiscalYear->code,
            'share_months' => $line->share_months,
            'amount' => ApiValue::money($line->amount_poisha),
            'status' => ApiValue::enum($line->status),
            'settled_at' => ApiValue::time($line->settled_at),
        ]);
    }
}
