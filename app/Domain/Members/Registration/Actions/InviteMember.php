<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Members\Actions\SetPortalPassword;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use App\Support\Contact\MobileNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Step 1 of self-registration (spec §6 R1): the office creates only a login — mobile and password.
 * The member signs in and fills in everything else. No member exists until the final approval.
 */
final class InviteMember
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, string $mobile, string $password): MemberApplication
    {
        Gate::forUser($actor)->authorize('create', MemberApplication::class);

        $normalized = MobileNumber::normalize($mobile);

        if ($normalized === null) {
            throw DomainRuleViolation::because('members.errors.mobile_format');
        }

        if (mb_strlen($password) < SetPortalPassword::MIN_LENGTH) {
            throw DomainRuleViolation::because('registration.errors.password_short', ['min' => SetPortalPassword::MIN_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): MemberApplication => DB::transaction(function () use ($actor, $normalized, $password): MemberApplication {
            // Two clicks on "Invite" for the same number wait for each other instead of racing the unique index.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['invite:'.$normalized]);

            if (Member::withTrashed()->where('mobile', $normalized)->exists()) {
                throw DomainRuleViolation::because('members.errors.mobile_taken', ['mobile' => $normalized]);
            }

            if (MemberApplication::openForMobile($normalized) !== null) {
                throw DomainRuleViolation::because('registration.errors.mobile_invited', ['mobile' => $normalized]);
            }

            $user = User::query()->create([
                'name' => $normalized,
                'email' => null,
                'password' => $password,
                'locale' => 'bn',
            ]);
            $user->assignRole(Role::Member->value);

            $application = MemberApplication::query()->create([
                'user_id' => $user->id,
                'mobile' => $normalized,
                'status' => MemberApplicationStatus::Invited,
                'invited_by' => $actor->id,
            ]);

            activity('members')->performedOn($application)->event('registration_invited')->log('registration invited');

            return $application;
        }, attempts: 3));
    }
}
