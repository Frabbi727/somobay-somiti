<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Data\SomitiProfileData;
use App\Domain\Settings\Models\SomitiProfile;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Contact\MobileNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Saves the society's name, registration, address, contact and logo (logged in the audit log).
 */
final class UpdateSomitiProfile
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, SomitiProfileData $data): SomitiProfile
    {
        Gate::forUser($actor)->authorize('update', SomitiProfile::class);

        if ($data->nameBn === '' || $data->nameEn === '') {
            throw DomainRuleViolation::because('somiti.errors.names_required');
        }

        if ($data->phone !== null && MobileNumber::normalize($data->phone) === null && preg_match('/^[0-9+\-\s]{6,20}$/', $data->phone) !== 1) {
            throw DomainRuleViolation::because('somiti.errors.phone_invalid');
        }

        if ($data->email !== null && filter_var($data->email, FILTER_VALIDATE_EMAIL) === false) {
            throw DomainRuleViolation::because('somiti.errors.email_invalid');
        }

        return $this->causer->withCauser($actor, fn (): SomitiProfile => DB::transaction(function () use ($actor, $data): SomitiProfile {
            $profile = SomitiProfile::query()->whereKey(SomitiProfile::ID)->lockForUpdate()->first() ?? new SomitiProfile(['id' => SomitiProfile::ID]);
            $oldLogo = $profile->logo_path;

            $profile->fill([
                'name_bn' => $data->nameBn,
                'name_en' => $data->nameEn,
                'registration_no' => $data->registrationNo,
                'registered_on' => $data->registeredOn,
                'address_bn' => $data->addressBn,
                'address_en' => $data->addressEn,
                'phone' => $data->phone,
                'email' => $data->email,
                'logo_path' => $data->logoPath,
                'updated_by' => $actor->id,
            ])->save();

            if ($oldLogo !== null && $oldLogo !== $data->logoPath) {
                DB::afterCommit(fn () => Storage::disk(SomitiProfile::LOGO_DISK)->delete($oldLogo));
            }

            return $profile;
        }, attempts: 3));
    }
}
