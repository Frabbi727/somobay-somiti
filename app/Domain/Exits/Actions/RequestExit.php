<?php

declare(strict_types=1);

namespace App\Domain\Exits\Actions;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Exits\Enums\ExitReason;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W9 step 1: records the request. From now on no dues are generated after the exit month.
 */
final class RequestExit
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Member $member, ExitReason $reason, string $details, YearMonth $exitMonth, Money $exitFee, ?int $resolutionId = null): MemberExit
    {
        Gate::forUser($actor)->authorize('create', MemberExit::class);

        if (mb_strlen(trim($details)) < 5) {
            throw DomainRuleViolation::because('members.errors.reason_required');
        }

        if ($exitFee->isNegative()) {
            throw DomainRuleViolation::because('exits.errors.fee_negative');
        }

        if ($exitMonth->isAfter(YearMonth::current())) {
            throw DomainRuleViolation::because('exits.errors.future_month');
        }

        return $this->causer->withCauser($actor, fn (): MemberExit => DB::transaction(function () use ($actor, $member, $reason, $details, $exitMonth, $exitFee, $resolutionId): MemberExit {
            $locked = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === MemberStatus::Exited) {
                throw DomainRuleViolation::because('exits.errors.already_exited');
            }

            if (MemberExit::query()->where('member_id', $locked->id)->where('status', '!=', ExitStatus::Cancelled)->exists()) {
                throw DomainRuleViolation::because('exits.errors.already_requested');
            }

            if ($exitMonth->isBefore(YearMonth::fromDate($locked->joined_on))) {
                throw DomainRuleViolation::because('exits.errors.before_joining');
            }

            $number = (int) DB::scalar("SELECT nextval('exit_no_seq')");

            return MemberExit::query()->create([
                'exit_no' => sprintf('X-%04d', $number),
                'member_id' => $locked->id,
                'reason_type' => $reason,
                'reason' => trim($details),
                'exit_month' => $exitMonth,
                'exit_fee_poisha' => $exitFee,
                'status' => ExitStatus::Requested,
                'resolution_id' => $resolutionId,
                'requested_by' => $actor->id,
            ]);
        }, attempts: 3));
    }

    /**
     * Payments still awaiting approval would change the settlement.
     */
    public static function hasPendingPayments(int $memberId): bool
    {
        return Payment::query()->where('member_id', $memberId)->where('status', PaymentStatus::Pending)->exists();
    }
}
