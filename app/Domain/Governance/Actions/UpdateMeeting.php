<?php

declare(strict_types=1);

namespace App\Domain\Governance\Actions;

use App\Domain\Governance\Data\MeetingData;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class UpdateMeeting
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Meeting $meeting, MeetingData $data): Meeting
    {
        return $this->causer->withCauser($actor, fn (): Meeting => DB::transaction(function () use ($actor, $meeting, $data): Meeting {
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);

            if (mb_strlen($data->title) < 3) {
                throw DomainRuleViolation::because('governance.errors.title_required');
            }

            if ($locked->type !== $data->type && $locked->attendees()->exists()) {
                throw DomainRuleViolation::because('governance.errors.type_frozen');
            }

            $locked->update($data->toAttributes());

            return $locked;
        }, attempts: 3));
    }
}
