<?php

declare(strict_types=1);

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Models\Resolution;
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
}
