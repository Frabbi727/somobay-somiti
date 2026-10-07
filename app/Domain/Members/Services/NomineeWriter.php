<?php

declare(strict_types=1);

namespace App\Domain\Members\Services;

use App\Domain\Members\Data\NomineeData;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\Nominee;
use App\Domain\Members\Models\NomineeRelation;

/**
 * Replaces a member's nominee list. Old nominees are soft-deleted so the history stays.
 */
final class NomineeWriter
{
    /**
     * @param  list<NomineeData>  $nominees
     */
    public function replace(Member $member, array $nominees): void
    {
        Nominee::query()->where('member_id', $member->id)->get()->each(fn (Nominee $nominee) => $nominee->delete());

        foreach ($nominees as $index => $nominee) {
            $relation = $nominee->relationId === null ? null : NomineeRelation::query()->find($nominee->relationId);

            Nominee::query()->create([
                'member_id' => $member->id,
                'name' => $nominee->name,
                'relation' => $relation === null ? $nominee->relation : $relation->label_en,
                'relation_id' => $relation?->id,
                'mobile' => $nominee->mobile,
                'nid' => $nominee->nid,
                'share_bps' => $nominee->share->value,
                'sort' => $index,
            ]);
        }
    }
}
