<?php

declare(strict_types=1);

namespace App\Domain\Governance\Services;

use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentTopUp;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Model;

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
     * One resolution backs one item: an investment, an added investment amount (top-up) or an exit.
     * Rejected, cancelled and reversed items release it. Locks the resolution row so two approvals
     * cannot both claim it; call inside the transaction that links it.
     */
    public function assertNotUsedElsewhere(ResolutionSubject $subject, ?int $resolutionId, ?Model $except = null): void
    {
        if ($resolutionId === null) {
            return;
        }

        $resolution = Resolution::query()->whereKey($resolutionId)->lockForUpdate()->first();

        if ($resolution === null) {
            return;
        }

        $others = match ($subject) {
            ResolutionSubject::Investment => [
                Investment::query()->whereNotIn('status', [InvestmentStatus::Rejected, InvestmentStatus::Cancelled, InvestmentStatus::Reversed]),
                InvestmentTopUp::query()->whereNotIn('status', [InvestmentTopUpStatus::Rejected, InvestmentTopUpStatus::Cancelled, InvestmentTopUpStatus::Reversed]),
            ],
            ResolutionSubject::Exit => [MemberExit::query()->where('status', '!=', ExitStatus::Cancelled)],
            default => [],
        };

        foreach ($others as $query) {
            $model = $query->getModel();
            $used = $query
                ->where('resolution_id', $resolutionId)
                ->when($except !== null && $except::class === $model::class, fn ($builder) => $builder->whereKeyNot($except?->getKey()))
                ->exists();

            if ($used) {
                throw DomainRuleViolation::because('governance.errors.resolution_in_use', ['number' => $resolution->resolution_no]);
            }
        }
    }
}
