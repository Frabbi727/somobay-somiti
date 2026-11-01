<?php

declare(strict_types=1);

namespace App\Domain\Members\Terms\Services;

use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Terms\Models\MembershipTerms;
use App\Domain\Members\Terms\Models\TermsAcceptance;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Has the member accepted the current rules? An approved member counts through the application
 * they came from, so acceptances are never copied.
 */
final class TermsGate
{
    public function needsAcceptance(Member $member): bool
    {
        return ! $this->currentAcceptances($member)->exists();
    }

    /**
     * The member's acceptance of the current version, directly or through their application.
     */
    public function currentAcceptance(Member $member): ?TermsAcceptance
    {
        return $this->currentAcceptances($member)->first();
    }

    /**
     * The member's most recent acceptance of any version, with the text they agreed to.
     */
    public function latestAcceptance(Member $member): ?TermsAcceptance
    {
        return TermsAcceptance::query()
            ->where(function (Builder $query) use ($member): void {
                $query->where('member_id', $member->getKey())
                    ->orWhereIn('application_id', MemberApplication::query()->select('id')->where('member_id', $member->getKey()));
            })
            ->with('terms')
            ->latest('accepted_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return Builder<TermsAcceptance>
     */
    private function currentAcceptances(Member $member): Builder
    {
        return TermsAcceptance::query()
            ->where('terms_id', MembershipTerms::current()->getKey())
            ->where(function (Builder $query) use ($member): void {
                $query->where('member_id', $member->getKey())
                    ->orWhereIn('application_id', MemberApplication::query()->select('id')->where('member_id', $member->getKey()));
            });
    }

    public function assertCurrent(int $version): MembershipTerms
    {
        $current = MembershipTerms::current();

        if ($current->version !== $version) {
            throw DomainRuleViolation::because('terms.errors.outdated');
        }

        return $current;
    }
}
