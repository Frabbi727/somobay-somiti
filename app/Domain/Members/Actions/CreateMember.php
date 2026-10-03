<?php

declare(strict_types=1);

namespace App\Domain\Members\Actions;

use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Events\MemberJoined;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Services\MemberRules;
use App\Domain\Members\Services\NomineeWriter;
use App\Domain\Members\Services\ShareChanger;
use App\Models\User;
use App\Support\Time\YearMonth;
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
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, MemberData $data, int $shares, YearMonth $effectiveFrom): Member
    {
        Gate::forUser($actor)->authorize('create', Member::class);
        $this->rules->assertValid($data);

        return $this->causer->withCauser($actor, fn (): Member => DB::transaction(function () use ($actor, $data, $shares, $effectiveFrom): Member {
            $number = (int) DB::scalar("SELECT nextval('member_no_seq')");

            $member = Member::query()->create([
                ...$data->toAttributes(),
                'member_no' => sprintf('M-%04d', $number),
                'status' => MemberStatus::Active,
                'created_by' => $actor->id,
            ]);

            $this->nominees->replace($member, $data->nominees);
            $this->shares->increase($actor, $member, $shares, $effectiveFrom, 'Joined');

            event(new MemberJoined($member));

            return $member;
        }, attempts: 3));
    }
}
