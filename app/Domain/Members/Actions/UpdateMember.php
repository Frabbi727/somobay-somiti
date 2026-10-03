<?php

declare(strict_types=1);

namespace App\Domain\Members\Actions;

use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Services\MemberRules;
use App\Domain\Members\Services\NomineeWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Updates personal details and nominees. Shares change only through ChangeShares.
 */
final class UpdateMember
{
    public function __construct(
        private readonly MemberRules $rules,
        private readonly NomineeWriter $nominees,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Member $member, MemberData $data): Member
    {
        return $this->causer->withCauser($actor, fn (): Member => DB::transaction(function () use ($actor, $member, $data): Member {
            $locked = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);
            $this->rules->assertValid($data, $locked);

            $locked->fill($data->toAttributes())->save();
            $this->nominees->replace($locked, $data->nominees);

            return $locked;
        }, attempts: 3));
    }
}
