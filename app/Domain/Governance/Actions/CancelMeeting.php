<?php

declare(strict_types=1);

namespace App\Domain\Governance\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Calls a meeting off; its open proposals are withdrawn with it.
 */
final class CancelMeeting
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Meeting $meeting, string $reason): Meeting
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): Meeting => DB::transaction(function () use ($actor, $meeting, $reason): Meeting {
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('cancel', $locked);

            $locked->resolutions()->where('status', ResolutionStatus::Proposed)->update(['status' => ResolutionStatus::Withdrawn]);
            $locked->update(['status' => MeetingStatus::Cancelled, 'cancel_reason' => $reason]);

            return $locked;
        }, attempts: 3));
    }
}
