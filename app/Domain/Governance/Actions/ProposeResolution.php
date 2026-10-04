<?php

declare(strict_types=1);

namespace App\Domain\Governance\Actions;

use App\Domain\Governance\Data\ResolutionData;
use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Enums\ResolutionStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class ProposeResolution
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Meeting $meeting, ResolutionData $data): Resolution
    {
        return $this->causer->withCauser($actor, fn (): Resolution => DB::transaction(function () use ($actor, $meeting, $data): Resolution {
            $locked = Meeting::query()->whereKey($meeting->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', [Resolution::class, $locked]);

            if ($locked->status === MeetingStatus::Cancelled) {
                throw DomainRuleViolation::because('governance.errors.meeting_cancelled');
            }

            if (mb_strlen($data->title) < 3 || mb_strlen($data->body) < 3) {
                throw DomainRuleViolation::because('governance.errors.resolution_text');
            }

            $number = (int) DB::scalar("SELECT nextval('resolution_no_seq')");

            return $locked->resolutions()->create([
                'resolution_no' => sprintf('R-%04d', $number),
                'subject' => $data->subject,
                'title' => $data->title,
                'body' => $data->body,
                'majority' => $data->majority,
                'status' => ResolutionStatus::Proposed,
                'proposed_by' => $actor->id,
            ]);
        }, attempts: 3));
    }
}
