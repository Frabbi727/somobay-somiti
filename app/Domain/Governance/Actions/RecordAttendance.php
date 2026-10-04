<?php

declare(strict_types=1);

namespace App\Domain\Governance\Actions;

use App\Domain\Governance\Models\Meeting;
use App\Domain\Governance\Services\Quorum;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Sets who was present (replacing the previous list) while the meeting is still open.
 * General meetings: active members. Committee meetings: active managing-committee users.
 */
final class RecordAttendance
{
    public function __construct(private readonly CauserResolver $causer) {}

    /**
     * @param  list<int>  $ids  member ids (general meetings) or user ids (committee meetings)
     */
    public function __invoke(User $actor, Meeting $meeting, array $ids): Meeting
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $this->causer->withCauser($actor, fn (): Meeting => DB::transaction(function () use ($actor, $meeting, $ids): Meeting {
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);

            $valid = $locked->type->isOfMembers()
                ? Member::query()->whereIn('id', $ids)->where('status', MemberStatus::Active)->count()
                : User::query()->whereIn('id', $ids)->whereNull('deactivated_at')
                    ->role(array_map(fn (Role $role): string => $role->value, Quorum::COMMITTEE_ROLES))->count();

            if ($valid !== count($ids)) {
                throw DomainRuleViolation::because('governance.errors.not_eligible');
            }

            $locked->attendees()->delete();

            $column = $locked->type->isOfMembers() ? 'member_id' : 'user_id';
            $locked->attendees()->createMany(array_map(fn (int $id): array => [$column => $id], $ids));

            activity('governance')->performedOn($locked)->withProperties(['attendees' => count($ids)])->log('attendance');

            return $locked;
        }, attempts: 3));
    }
}
