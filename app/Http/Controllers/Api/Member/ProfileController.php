<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Models\Nominee;
use App\Domain\Members\Portal\ChangeOwnPassword;
use App\Http\Api\ApiResponse;
use App\Http\Api\ApiValue;
use App\Http\Controllers\Api\Member\Concerns\ResolvesMember;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The portal profile (read-only details and nominees) and the member's own password change.
 */
final class ProfileController
{
    use ResolvesMember;

    public function show(Request $request): JsonResponse
    {
        $member = self::member($request)->load('nominees.nomineeRelation');

        return ApiResponse::ok([
            'member_no' => $member->member_no,
            'name' => self::memberName($member),
            'name_bn' => $member->name_bn,
            'name_en' => $member->name_en,
            'mobile' => $member->mobile,
            'joined_on' => ApiValue::date($member->joined_on),
            'status' => ApiValue::enum($member->status),
            'nominees' => $member->nominees->map(fn (Nominee $nominee): array => [
                'name' => $nominee->name,
                'relation' => $nominee->relationLabel(),
                'share_percent' => $nominee->share()->toPercentString(),
                'share_display' => $nominee->share()->format(app()->getLocale()),
            ])->values()->all(),
        ]);
    }

    /**
     * Signs out every other device of this member (this device keeps its token pair).
     */
    public function changePassword(ChangePasswordRequest $request, ChangeOwnPassword $change): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $family = substr($user->currentAccessToken()->name, strlen('access:'));
        $change($user, (string) $request->string('current_password'), (string) $request->string('password'), keepTokenFamily: $family);

        return ApiResponse::ok(null, __('portal.profile.password_saved'));
    }
}
