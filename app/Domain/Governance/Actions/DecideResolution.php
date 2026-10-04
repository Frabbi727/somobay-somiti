<?php

declare(strict_types=1);

namespace App\Domain\Governance\Actions;

use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Records the vote. Only a held meeting that reached quorum can decide; no more votes than people
 * present. Passed or rejected follows from the votes and the resolution's majority rule.
 */
final class DecideResolution
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Resolution $resolution, int $for, int $against, int $abstain): Resolution
    {
        return $this->causer->withCauser($actor, fn (): Resolution => DB::transaction(function () use ($actor, $resolution, $for, $against, $abstain): Resolution {
            $locked = Resolution::query()->whereKey($resolution->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('decide', $locked);

            $meeting = Meeting::query()->whereKey($locked->meeting_id)->sharedLock()->firstOrFail();

            if ($meeting->status !== MeetingStatus::Held) {
                throw DomainRuleViolation::because('governance.errors.meeting_not_held', ['number' => $meeting->meeting_no]);
            }

            if ($meeting->quorum_met !== true) {
                throw DomainRuleViolation::because('governance.errors.no_quorum', ['number' => $meeting->meeting_no]);
            }

            if ($for < 0 || $against < 0 || $abstain < 0 || $for + $against + $abstain > (int) $meeting->attendees_count) {
                throw DomainRuleViolation::because('governance.errors.votes', ['present' => (int) $meeting->attendees_count]);
            }

            $locked->update([
                'status' => $locked->majority->passes($for, $against) ? ResolutionStatus::Passed : ResolutionStatus::Rejected,
                'votes_for' => $for,
                'votes_against' => $against,
                'votes_abstain' => $abstain,
                'decided_at' => CarbonImmutable::now(),
                'decided_by' => $actor->id,
            ]);

            return $locked;
        }, attempts: 3));
    }
}
