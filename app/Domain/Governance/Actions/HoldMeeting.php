<?php

declare(strict_types=1);

namespace App\Domain\Governance\Actions;

use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Governance\Services\Quorum;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Closes the meeting as held: snapshots who could attend, who did and the quorum, and stores the
 * minutes. Resolutions are then decided on these figures; nothing about the meeting changes later.
 */
final class HoldMeeting
{
    public function __construct(
        private readonly Quorum $quorum,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Meeting $meeting, ?string $minutes = null): Meeting
    {
        return $this->causer->withCauser($actor, fn (): Meeting => DB::transaction(function () use ($actor, $meeting, $minutes): Meeting {
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('hold', $locked);

            if ($locked->scheduled_at->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay())) {
                throw DomainRuleViolation::because('governance.errors.not_yet');
            }

            $eligible = $this->quorum->eligible($locked->type);
            $required = $this->quorum->required($locked->type, $eligible);
            $present = $locked->attendees()->count();

            $locked->update([
                'status' => MeetingStatus::Held,
                'eligible_count' => $eligible,
                'attendees_count' => $present,
                'quorum_required' => $required,
                'quorum_met' => $present >= $required,
                'minutes' => $minutes === null || trim($minutes) === '' ? null : trim($minutes),
                'held_at' => CarbonImmutable::now(),
                'held_recorded_by' => $actor->id,
            ]);

            return $locked;
        }, attempts: 3));
    }
}
