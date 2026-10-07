<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Actions;

use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Services\ApproverNotifier;
use App\Domain\Members\Services\MemberRules;
use App\Domain\Settings\Models\SomitiProfile;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The member sends their registration for approval (spec §6 R3). The full member rules run now;
 * the approval chain is copied onto the registration so later changes to it do not affect it.
 * The same idempotency key answers with the registration as it is — a retry never submits twice.
 */
final class SubmitRegistration
{
    public function __construct(
        private readonly MemberRules $rules,
        private readonly ApproverNotifier $notifier,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(MemberApplication $application, string $idempotencyKey): MemberApplication
    {
        return $this->causer->withCauser($application->user, fn (): MemberApplication => DB::transaction(function () use ($application, $idempotencyKey): MemberApplication {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['registration-submit:'.$idempotencyKey]);

            if (MemberApplication::query()->where('submit_idempotency_key', $idempotencyKey)->whereKeyNot($application->getKey())->exists()) {
                throw DomainRuleViolation::because('registration.errors.idempotency_conflict');
            }

            $locked = MemberApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->submit_idempotency_key === $idempotencyKey) {
                return $locked;
            }

            if (! $locked->status->isEditable()) {
                throw DomainRuleViolation::because('registration.errors.not_editable');
            }

            $locked->load('nominees');
            $this->rules->assertValid($locked->toMemberData(CarbonImmutable::now(YearMonth::TIMEZONE)), null, $locked->id);

            if (($locked->requested_shares ?? 0) < 1) {
                throw DomainRuleViolation::because('registration.errors.shares_required');
            }

            $nidInUse = $locked->nid !== null && MemberApplication::query()
                ->whereKeyNot($locked->getKey())
                ->whereIn('status', MemberApplicationStatus::openValues())
                ->where('nid', $locked->nid)
                ->exists();

            if ($nidInUse) {
                throw DomainRuleViolation::because('registration.errors.nid_taken');
            }

            $chain = SomitiProfile::current()->registrationApprovalChain();

            if ($chain === []) {
                throw DomainRuleViolation::because('registration.errors.chain_empty');
            }

            $locked->forceFill([
                'status' => MemberApplicationStatus::Submitted,
                'approval_chain' => array_map(fn (Role $role): string => $role->value, $chain),
                'current_step' => 0,
                'submission_no' => $locked->submission_no + 1,
                'submitted_at' => CarbonImmutable::now(),
                'decided_at' => null,
                'submit_idempotency_key' => $idempotencyKey,
            ])->save();

            activity('members')->performedOn($locked)->event('registration_submitted')->withProperties(['submission_no' => $locked->submission_no])->log('registration submitted');

            $this->notifier->notifyStep($locked);

            return $locked;
        }, attempts: 3));
    }
}
