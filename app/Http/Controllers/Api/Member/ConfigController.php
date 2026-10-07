<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Settings\Models\SomitiProfile;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The society's own details for the app's start and "about" screens (no sign-in needed).
 */
final class ConfigController
{
    public function somitiInfo(): JsonResponse
    {
        $profile = SomitiProfile::current();

        return ApiResponse::ok([
            'name' => $profile->displayName(),
            'name_bn' => $profile->name_bn,
            'name_en' => $profile->name_en,
            'registration_no' => $profile->registration_no,
            'address' => $profile->displayAddress(),
            'phone' => $profile->phone,
            'email' => $profile->email,
            'logo_url' => $profile->logo_path === null ? null : URL::temporarySignedRoute('api.logo', now()->addHour()),
            'otp_enabled' => (bool) config('somiti.portal_otp'),
        ]);
    }

    /**
     * The relation list for the nominee dropdown, in the request language (no sign-in needed).
     */
    public function nomineeRelations(): JsonResponse
    {
        return ApiResponse::ok(NomineeRelation::query()->where('active', true)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (NomineeRelation $relation): array => ['id' => $relation->id, 'key' => $relation->key, 'label' => $relation->label()])
            ->values()->all());
    }

    public function logo(): Response
    {
        $profile = SomitiProfile::current();
        abort_if($profile->logo_path === null || ! Storage::disk(SomitiProfile::LOGO_DISK)->exists($profile->logo_path), 404);

        return Storage::disk(SomitiProfile::LOGO_DISK)->response($profile->logo_path);
    }
}
