<?php

declare(strict_types=1);

namespace App\Domain\Governance\Actions;

use App\Domain\Governance\Data\MeetingData;
use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class CreateMeeting
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, MeetingData $data, bool $schedule = true): Meeting
    {
        Gate::forUser($actor)->authorize('create', Meeting::class);

        if (mb_strlen($data->title) < 3) {
            throw DomainRuleViolation::because('governance.errors.title_required');
        }

        return $this->causer->withCauser($actor, fn (): Meeting => DB::transaction(function () use ($actor, $data, $schedule): Meeting {
            $number = (int) DB::scalar("SELECT nextval('meeting_no_seq')");

            return Meeting::query()->create([
                ...$data->toAttributes(),
                'meeting_no' => sprintf('MT-%04d', $number),
                'status' => $schedule ? MeetingStatus::Scheduled : MeetingStatus::Draft,
                'created_by' => $actor->id,
            ]);
        }, attempts: 3));
    }
}
