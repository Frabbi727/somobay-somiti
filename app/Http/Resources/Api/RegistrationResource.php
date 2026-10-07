<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Domain\Members\Registration\Data\TimelineStep;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Members\Registration\Models\MemberApplicationNominee;
use App\Domain\Members\Registration\Services\RegistrationTimeline;
use App\Http\Api\ApiValue;
use Illuminate\Support\Facades\URL;

/**
 * The registration as the app shows it (spec §8.4): status, the one next action, the timeline
 * with role names already translated, the decision to read, and the draft itself.
 */
final class RegistrationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(MemberApplication $application): array
    {
        $timeline = app(RegistrationTimeline::class);
        $decision = $timeline->decision($application);
        $application->loadMissing('nominees.nomineeRelation');

        return [
            'status' => ApiValue::enum($application->status),
            'next_action' => $application->nextAction()->value,
            'can_edit' => $application->status->isEditable(),
            'headline' => $timeline->headline($application),
            'message' => $timeline->message($application),
            'timeline' => array_map(fn (TimelineStep $step): array => [
                'key' => $step->key,
                'label' => $step->label,
                'state' => $step->state->value,
                'acted_at' => ApiValue::time($step->actedAt),
                'actor' => $step->actorName,
                'reason' => $step->reason,
            ], $timeline->steps($application)),
            'decision' => $decision === null ? null : [
                'type' => ApiValue::enum($decision->decision),
                'by_role' => $decision->role->getLabel(),
                'at' => ApiValue::time($decision->created_at),
                'reason' => $decision->reason,
            ],
            'data' => [
                'name_bn' => $application->name_bn,
                'name_en' => $application->name_en,
                'guardian_name' => $application->guardian_name,
                'nid' => $application->nid,
                'date_of_birth' => ApiValue::date($application->date_of_birth),
                'mobile' => $application->mobile,
                'email' => $application->email,
                'address' => $application->address,
                'photo_url' => $application->photo_path === null ? null : URL::temporarySignedRoute('api.registration.photo', now()->addHour(), ['application' => $application->id]),
                'requested_shares' => $application->requested_shares,
                'nominees' => $application->nominees->map(fn (MemberApplicationNominee $nominee): array => [
                    'name' => $nominee->name,
                    'relation_id' => $nominee->relation_id,
                    'relation' => $nominee->nomineeRelation?->label(),
                    'mobile' => $nominee->mobile,
                    'nid' => $nominee->nid,
                    'share_percent' => $nominee->share()->toPercentString(),
                ])->values()->all(),
            ],
        ];
    }
}
