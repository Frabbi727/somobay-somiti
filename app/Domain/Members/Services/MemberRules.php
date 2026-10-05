<?php

declare(strict_types=1);

namespace App\Domain\Members\Services;

use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Data\NomineeData;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Money\Bps;

final class MemberRules
{
    public function assertValid(MemberData $data, ?Member $existing = null): void
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

        if ($data->nid !== null && (clone $others)->where('nid', $data->nid)->exists()) {
            throw DomainRuleViolation::because('members.errors.nid_taken');
        }

        $this->assertNominees($data->nominees);
    }

    /**
     * Nominees are optional, but when given their shares must add up to exactly 100% and a mobile,
     * if entered, must be a valid Bangladeshi number.
     *
     * @param  list<NomineeData>  $nominees
     */
    private function assertNominees(array $nominees): void
    {
        if ($nominees === []) {
            return;
        }

        $total = 0;

        foreach ($nominees as $nominee) {
            if ($nominee->name === '' || $nominee->relation === '' || $nominee->share->isZero()) {
                throw DomainRuleViolation::because('members.errors.nominee_incomplete');
            }

            if ($nominee->mobile !== null && preg_match('/^01[3-9]\d{8}$/', $nominee->mobile) !== 1) {
                throw DomainRuleViolation::because('members.errors.nominee_mobile_format', ['name' => $nominee->name]);
            }

            $total += $nominee->share->value;
        }

        if ($total !== 10_000) {
            throw DomainRuleViolation::because('members.errors.nominee_total', ['total' => Bps::of($total)->format(app()->getLocale())]);
        }
    }
}
