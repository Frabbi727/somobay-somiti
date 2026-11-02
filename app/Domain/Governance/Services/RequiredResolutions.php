<?php

declare(strict_types=1);

namespace App\Domain\Governance\Services;

use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Shared\Exceptions\DomainRuleViolation;

/**
 * "Linked resolution required where configured" (Phase 9): approvals of the subjects listed in
 * somiti.require_resolution_for need a passed resolution on that subject.
 */
final class RequiredResolutions
{
    public function assertSatisfied(ResolutionSubject $subject, ?int $resolutionId): void
    {
        if (! $subject->isRequired()) {
            return;
        }

        if ($resolutionId === null) {
            throw DomainRuleViolation::because('governance.errors.resolution_required', ['subject' => $subject->getLabel()]);
        }

        $this->assertUsable($subject, Resolution::query()->findOrFail($resolutionId));
    }

    /**
     * A resolution can back a decision only if it passed and is about that subject.
     */
    public function assertUsable(ResolutionSubject $subject, Resolution $resolution): void
    {
        if ($resolution->status !== ResolutionStatus::Passed || $resolution->subject !== $subject) {
            throw DomainRuleViolation::because('governance.errors.resolution_unusable', [
                'number' => $resolution->resolution_no,
                'subject' => $subject->getLabel(),
            ]);
        }
    }

    /**
     * One resolution backs one investment or one exit: a resolution already linked to another live
     * (not rejected or cancelled) investment or exit cannot be used again.
     */
    public function assertNotUsedElsewhere(ResolutionSubject $subject, ?int $resolutionId, ?int $exceptId = null): void
    {
        if ($resolutionId === null) {
            return;
        }

        $query = match ($subject) {
            ResolutionSubject::Investment => Investment::query()->whereNotIn('status', [InvestmentStatus::Rejected, InvestmentStatus::Cancelled]),
            ResolutionSubject::Exit => MemberExit::query()->where('status', '!=', ExitStatus::Cancelled),
            default => null,
        };

        if ($query?->where('resolution_id', $resolutionId)->when($exceptId !== null, fn ($builder) => $builder->whereKeyNot($exceptId))->exists() === true) {
            throw DomainRuleViolation::because('governance.errors.resolution_in_use', [
                'number' => (string) Resolution::query()->whereKey($resolutionId)->value('resolution_no'),
            ]);
        }
    }
}
