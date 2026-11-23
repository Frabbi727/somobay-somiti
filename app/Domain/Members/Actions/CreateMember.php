<?php

declare(strict_types=1);

namespace App\Domain\Members\Actions;

use App\Domain\Contributions\BackDues\BackDuesPlanner;
use App\Domain\Contributions\BackDues\BackDuesWriter;
use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Events\MemberJoined;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Members\Services\MemberRules;
use App\Domain\Members\Services\NomineeWriter;
use App\Domain\Members\Services\ShareChanger;
use App\Domain\Members\Terms\Data\TermsConsent;
use App\Domain\Members\Terms\Models\TermsAcceptance;
use App\Domain\Members\Terms\Services\TermsGate;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Onboarding (W1): creates the member (M-0001, M-0002, …), their nominees and first share lot,
 * and charges the registration fee for those shares at the effective month's rate.
 */
final class CreateMember
{
    public function __construct(
        private readonly MemberRules $rules,
        private readonly ShareChanger $shares,
        private readonly NomineeWriter $nominees,
        private readonly PortalAccounts $portal,
        private readonly TermsGate $terms,
        private readonly CauserResolver $causer,
        private readonly BackDuesPlanner $backDues,
        private readonly BackDuesWriter $backDuesWriter,
    ) {}

    /**
     * @param  YearMonth|null  $duesStartMonth  bill from this month (late joiner); null = the share start month
     */
    public function __invoke(User $actor, MemberData $data, int $shares, YearMonth $effectiveFrom, ?User $portalUser = null, ?TermsConsent $consent = null, ?YearMonth $duesStartMonth = null): Member
    {
        Gate::forUser($actor)->authorize('create', Member::class);
        $this->rules->assertValid($data);
        $duesStart = $duesStartMonth ?? $effectiveFrom;

        return $this->causer->withCauser($actor, fn (): Member => DB::transaction(function () use ($actor, $data, $shares, $effectiveFrom, $portalUser, $consent, $duesStart): Member {
            $backDues = $this->backDues->plan($shares, $duesStart, $effectiveFrom);
            $number = (int) DB::scalar("SELECT nextval('member_no_seq')");

            $member = Member::query()->create([
                ...$data->toAttributes(),
                'member_no' => sprintf('M-%04d', $number),
                'status' => MemberStatus::Active,
                'dues_start_month' => $duesStart,
                'created_by' => $actor->id,
            ]);

            $this->nominees->replace($member, $data->nominees);
            $lot = $this->shares->increase($actor, $member, $shares, $effectiveFrom, 'Joined');
            $this->backDuesWriter->write($actor, $member, $lot, $backDues, CarbonImmutable::now(YearMonth::TIMEZONE));
            $this->portal->forMember($member, $portalUser);

            if ($consent !== null) {
                TermsAcceptance::query()->create([
                    'terms_id' => $this->terms->assertCurrent($consent->version)->getKey(),
                    'member_id' => $member->getKey(),
                    'user_id' => $actor->id,
                    'channel' => $consent->channel,
                    'ip' => $consent->ip,
                    'idempotency_key' => $consent->idempotencyKey,
                    'accepted_at' => now(),
                ]);
            }

            event(new MemberJoined($member));

            return $member;
        }, attempts: 3));
    }
}
