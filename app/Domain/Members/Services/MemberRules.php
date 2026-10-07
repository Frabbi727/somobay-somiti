<?php

declare(strict_types=1);

namespace App\Domain\Members\Services;

use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Shared\Exceptions\DomainRuleViolation;

final class MemberRules
{
    public function __construct(private readonly NomineeRules $nominees) {}

    public function assertValid(MemberData $data, ?Member $existing = null, ?int $ignoreApplicationId = null): void
    {
        if ($data->nameBn === '' || $data->nameEn === '') {
            throw DomainRuleViolation::because('members.errors.names_required');
        }

        if (preg_match('/^01[3-9]\d{8}$/', $data->mobile) !== 1) {
            throw DomainRuleViolation::because('members.errors.mobile_format');
        }

        if ($data->nid !== null && preg_match('/^(\d{10}|\d{13}|\d{17})$/', $data->nid) !== 1) {
            throw DomainRuleViolation::because('members.errors.nid_format');
        }

        $others = Member::withTrashed()->when($existing !== null, fn ($query) => $query->whereKeyNot($existing?->getKey()));

        if ((clone $others)->where('mobile', $data->mobile)->exists()) {
            throw DomainRuleViolation::because('members.errors.mobile_taken', ['mobile' => $data->mobile]);
        }

        $openRegistration = MemberApplication::openForMobile($data->mobile);

        if ($openRegistration !== null && $openRegistration->id !== $ignoreApplicationId) {
            throw DomainRuleViolation::because('registration.errors.mobile_invited', ['mobile' => $data->mobile]);
        }

        if ($data->nid !== null && (clone $others)->where('nid', $data->nid)->exists()) {
            throw DomainRuleViolation::because('members.errors.nid_taken');
        }

        $this->nominees->assertValid($data->nominees);
    }
}
