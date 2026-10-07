<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Services\ApproverNotifier;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Services\SmsSender;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Permission;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * One approver's decision on the current step (spec §6 R4-R7). Approve moves to the next role;
 * the last approval creates the member with the existing onboarding (member number, shares,
 * registration fee, welcome SMS). Return sends it back for correction; reject closes it for good.
 */
final class DecideRegistration
{
    public const int MIN_REASON = 5;

    public function __construct(
        private readonly CreateMember $createMember,
        private readonly ApproverNotifier $notifier,
        private readonly SmsSender $sms,
        private readonly MemberTokens $tokens,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(
        User $actor,
        MemberApplication $application,
        RegistrationDecisionType $decision,
        ?string $reason = null,
        ?int $shares = null,
        ?YearMonth $effectiveFrom = null,
    ): MemberApplication {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if ($decision !== RegistrationDecisionType::Approve && mb_strlen((string) $reason) < self::MIN_REASON) {
            throw DomainRuleViolation::because('registration.errors.reason_required');
        }

        return $this->causer->withCauser($actor, fn (): MemberApplication => DB::transaction(function () use ($actor, $application, $decision, $reason, $shares, $effectiveFrom): MemberApplication {
            $locked = MemberApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $locked);

            if ($locked->status !== MemberApplicationStatus::Submitted) {
                throw DomainRuleViolation::because('registration.errors.not_pending');
            }

            $role = $locked->currentRole();

            if ($role === null || ! $actor->hasAnyOf($role)) {
                throw DomainRuleViolation::because('registration.errors.not_your_step', ['role' => $role?->getLabel() ?? '—']);
            }

            if ($locked->user_id === $actor->id) {
                throw DomainRuleViolation::because('registration.errors.own_registration');
            }

            if ($locked->hasDecidedThisSubmission($actor)) {
                throw DomainRuleViolation::because('registration.errors.already_decided');
            }

            $step = (int) $locked->current_step;

            $locked->decisions()->create([
                'submission_no' => $locked->submission_no,
                'step' => $step,
                'role' => $role,
                'user_id' => $actor->id,
                'decision' => $decision,
                'reason' => $reason,
            ]);

            match (true) {
                $decision === RegistrationDecisionType::Approve && ! $locked->isLastStep() => $this->advance($locked),
                $decision === RegistrationDecisionType::Approve => $this->activate($actor, $locked, $shares, $effectiveFrom),
                $decision === RegistrationDecisionType::Return => $this->sendBack($locked, (string) $reason),
                $decision === RegistrationDecisionType::Reject => $this->reject($locked, (string) $reason),
            };

            activity('members')->performedOn($locked)->event('registration_'.$decision->value)
                ->withProperties(['step' => $step, 'role' => $role->value, 'reason' => $reason])
                ->log('registration '.$decision->value);

            return $locked;
        }, attempts: 3));
    }

    private function advance(MemberApplication $application): void
    {
        $application->forceFill(['current_step' => (int) $application->current_step + 1])->save();
        $this->notifier->notifyStep($application);
    }

    private function activate(User $actor, MemberApplication $application, ?int $shares, ?YearMonth $effectiveFrom): void
    {
        if ($effectiveFrom === null) {
            throw DomainRuleViolation::because('registration.errors.effective_from_required');
        }

        $count = $shares ?? (int) $application->requested_shares;

        if ($count < 1) {
            throw DomainRuleViolation::because('members.errors.shares_positive');
        }

        if (! $actor->may(Permission::MembersCreate)) {
            throw DomainRuleViolation::because('registration.errors.final_needs_create');
        }

        $application->load('nominees');
        $data = $application->toMemberData(CarbonImmutable::now(YearMonth::TIMEZONE)->startOfDay());

        // Closed first, so the mobile is no longer "invited" when the member rules check it.
        $application->forceFill(['status' => MemberApplicationStatus::Approved, 'current_step' => null, 'decided_at' => CarbonImmutable::now()])->save();

        $member = ($this->createMember)($actor, $data, $count, $effectiveFrom, $application->user);

        $application->forceFill(['member_id' => $member->id])->save();
        $this->tokens->revokeAccessTokens($application->user);
    }

    private function sendBack(MemberApplication $application, string $reason): void
    {
        $application->forceFill(['status' => MemberApplicationStatus::Returned, 'current_step' => null, 'decided_at' => CarbonImmutable::now()])->save();

        $this->sms->template(SmsTemplateKey::RegistrationReturned, $application->mobile, [
            'reason' => $reason,
            'portal_url' => url('/portal'),
        ], null, sprintf('registration:%d:%d:return', $application->id, $application->submission_no), $application);
    }

    private function reject(MemberApplication $application, string $reason): void
    {
        $application->forceFill(['status' => MemberApplicationStatus::Rejected, 'current_step' => null, 'decided_at' => CarbonImmutable::now()])->save();
        $this->tokens->revokeAll($application->user);

        $this->sms->template(SmsTemplateKey::RegistrationRejected, $application->mobile, [
            'reason' => $reason,
        ], null, sprintf('registration:%d:%d:reject', $application->id, $application->submission_no), $application);
    }
}
