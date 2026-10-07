<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Services;

use App\Domain\Members\Registration\Data\TimelineStep;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Enums\TimelineState;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Models\MemberApplicationDecision;
use App\Domain\Settings\Models\SomitiProfile;

/**
 * Turns a registration into what a member can read: submitted → each approval → active, with
 * who acted, when and why. Role names come from the chain, so the apps never hard-code them.
 */
final class RegistrationTimeline
{
    /**
     * @return list<TimelineStep>
     */
    public function steps(MemberApplication $application): array
    {
        $invited = $application->status === MemberApplicationStatus::Invited;
        $chain = $invited ? SomitiProfile::current()->registrationApprovalChain() : $application->chain();
        $decisions = $invited ? collect() : $application->currentDecisions()->keyBy('step');

        $steps = [new TimelineStep(
            'submitted',
            __('registration.timeline.submitted'),
            $invited ? TimelineState::Pending : TimelineState::Done,
            $application->submitted_at,
        )];

        foreach ($chain as $index => $role) {
            /** @var MemberApplicationDecision|null $decision */
            $decision = $decisions->get($index);

            $state = match (true) {
                $decision?->decision === RegistrationDecisionType::Approve => TimelineState::Done,
                $decision?->decision === RegistrationDecisionType::Return => TimelineState::Returned,
                $decision?->decision === RegistrationDecisionType::Reject => TimelineState::Rejected,
                $application->status === MemberApplicationStatus::Submitted && $application->current_step === $index => TimelineState::Pending,
                default => TimelineState::Waiting,
            };

            $steps[] = new TimelineStep(
                'step_'.$index,
                __('registration.timeline.step', ['role' => $role->getLabel()]),
                $state,
                $decision?->created_at,
                $decision?->user->name,
                $decision?->reason,
            );
        }

        $approved = $application->status === MemberApplicationStatus::Approved;
        $steps[] = new TimelineStep(
            'activation',
            __('registration.timeline.activation'),
            $approved ? TimelineState::Done : TimelineState::Waiting,
            $approved ? $application->decided_at : null,
        );

        return $steps;
    }

    public function headline(MemberApplication $application): string
    {
        return match ($application->status) {
            MemberApplicationStatus::Invited => __('registration.headline.invited'),
            MemberApplicationStatus::Submitted => __('registration.headline.waiting', ['role' => $application->currentRole()?->getLabel() ?? '']),
            MemberApplicationStatus::Returned => __('registration.headline.returned'),
            MemberApplicationStatus::Rejected => __('registration.headline.rejected'),
            MemberApplicationStatus::Approved => __('registration.headline.approved'),
        };
    }

    public function message(MemberApplication $application): string
    {
        $last = $application->status === MemberApplicationStatus::Invited ? null : $application->currentDecisions()->last();
        $current = $application->currentRole()?->getLabel() ?? '';

        return match ($application->status) {
            MemberApplicationStatus::Invited => __('registration.message.invited'),
            MemberApplicationStatus::Submitted => $last === null
                ? __('registration.message.waiting_first', ['role' => $current])
                : __('registration.message.waiting_next', ['done' => $last->role->getLabel(), 'role' => $current]),
            MemberApplicationStatus::Returned => __('registration.message.returned', ['role' => $last?->role->getLabel() ?? '']),
            MemberApplicationStatus::Rejected => __('registration.message.rejected', ['role' => $last?->role->getLabel() ?? '']),
            MemberApplicationStatus::Approved => __('registration.message.approved', ['member_no' => $application->member()->value('member_no') ?? '']),
        };
    }

    /**
     * The return or rejection the member must read, if that is where the registration stands.
     */
    public function decision(MemberApplication $application): ?MemberApplicationDecision
    {
        if (! in_array($application->status, [MemberApplicationStatus::Returned, MemberApplicationStatus::Rejected], true)) {
            return null;
        }

        return $application->currentDecisions()->last();
    }
}
