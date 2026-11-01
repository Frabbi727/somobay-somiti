<?php

declare(strict_types=1);

namespace App\Domain\Members\Services;

use App\Domain\Geo\Services\AddressRules;
use App\Domain\Members\Data\NomineeData;
use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Money\Bps;

/**
 * Nominees, for the office form and for self-registration alike: at least one; each with a name,
 * a relation from the list, an NID and a share; optional mobile valid; shares add up to 100%.
 */
final class NomineeRules
{
    public function __construct(private readonly AddressRules $addresses) {}

    /**
     * @param  list<NomineeData>  $nominees
     * @param  bool  $requireFullProfile  true for a new member: gender and both addresses are needed
     */
    public function assertValid(array $nominees, bool $requireFullProfile = true): void
    {
        if ($nominees === []) {
            throw DomainRuleViolation::because('members.errors.nominee_required');
        }

        $total = 0;

        foreach ($nominees as $nominee) {
            if ($nominee->name === '' || $nominee->share->isZero()) {
                throw DomainRuleViolation::because('members.errors.nominee_incomplete');
            }

            if (! NomineeRelation::isActive($nominee->relationId)) {
                throw DomainRuleViolation::because('members.errors.nominee_relation', ['name' => $nominee->name]);
            }

            if ($nominee->nid === null) {
                throw DomainRuleViolation::because('members.errors.nominee_nid_required', ['name' => $nominee->name]);
            }

            if (preg_match('/^(\d{10}|\d{13}|\d{17})$/', $nominee->nid) !== 1) {
                throw DomainRuleViolation::because('members.errors.nominee_nid_format', ['name' => $nominee->name]);
            }

            if ($nominee->mobile !== null && preg_match('/^01[3-9]\d{8}$/', $nominee->mobile) !== 1) {
                throw DomainRuleViolation::because('members.errors.nominee_mobile_format', ['name' => $nominee->name]);
            }

            if ($requireFullProfile && $nominee->gender === null) {
                throw DomainRuleViolation::because('members.errors.nominee_gender_required', ['name' => $nominee->name]);
            }

            if ($nominee->passportNo !== null && preg_match('/^[A-Z0-9]{6,20}$/', $nominee->passportNo) !== 1) {
                throw DomainRuleViolation::because('members.errors.passport_format');
            }

            $this->addresses->assertValid($nominee->presentAddress, $requireFullProfile, 'members.address.nominee_present', ['name' => $nominee->name]);
            $this->addresses->assertValid($nominee->permanentAddress, $requireFullProfile, 'members.address.nominee_permanent', ['name' => $nominee->name]);

            $total += $nominee->share->value;
        }

        if ($total !== 10_000) {
            throw DomainRuleViolation::because('members.errors.nominee_total', ['total' => Bps::of($total)->format(app()->getLocale())]);
        }
    }
}
