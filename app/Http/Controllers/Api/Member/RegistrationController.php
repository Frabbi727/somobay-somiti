<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Member;

use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Http\Api\ApiResponse;
use App\Http\Controllers\Api\Member\Concerns\ResolvesApplication;
use App\Http\Requests\Api\RegistrationPhotoRequest;
use App\Http\Requests\Api\SaveRegistrationRequest;
use App\Http\Requests\Api\SubmitRegistrationRequest;
use App\Http\Resources\Api\RegistrationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's own registration in the app (spec §8.4-8.7). The registration always comes from
 * the token, never from input.
 */
final class RegistrationController
{
    use ResolvesApplication;

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(RegistrationResource::make(self::application($request)));
    }

    public function update(SaveRegistrationRequest $request, SaveRegistrationDraft $save): JsonResponse
    {
        $application = $save(self::application($request), RegistrationDraft::fromInput($request->validated()));

        return ApiResponse::ok(RegistrationResource::make($application), __('registration.notifications.draft_saved'));
    }

    public function photo(RegistrationPhotoRequest $request, SaveRegistrationDraft $save): JsonResponse
    {
        $path = (string) $request->file('photo')?->store('member-photos', 'local');

        try {
            $application = $save(self::application($request), RegistrationDraft::fromInput(['photo_path' => $path]));
        } catch (DomainRuleViolation $violation) {
            Storage::disk('local')->delete($path);

            throw $violation;
        }

        return ApiResponse::ok(RegistrationResource::make($application), __('registration.notifications.draft_saved'));
    }

    public function submit(SubmitRegistrationRequest $request, SubmitRegistration $submit): JsonResponse
    {
        $application = $submit(self::application($request), (string) $request->string('idempotency_key'));

        return ApiResponse::ok(RegistrationResource::make($application), __('registration.notifications.submitted'));
    }

    /**
     * Token-free on purpose: the app shows it in an image widget; the signature protects it.
     */
    public function photoFile(MemberApplication $application): Response
    {
        abort_if($application->photo_path === null || ! Storage::disk('local')->exists($application->photo_path), 404);

        return Storage::disk('local')->response($application->photo_path);
    }
}
