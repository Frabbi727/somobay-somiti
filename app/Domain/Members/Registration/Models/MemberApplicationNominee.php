<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Models;

use App\Domain\Members\Enums\Gender;
use App\Domain\Members\Models\NomineeRelation;
use App\Policies\MemberApplicationNomineePolicy;
use App\Support\Money\Bps;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A nominee on a registration draft. Replaced as a whole while the applicant may still edit.
 *
 * @property int $id
 * @property int $application_id
 * @property string $name
 * @property int|null $relation_id
 * @property string|null $mobile
 * @property string|null $nid
 * @property Gender|null $gender
 * @property string|null $passport_no
 * @property int|null $present_upazila_id
 * @property int|null $present_union_id
 * @property string|null $present_address_line
 * @property int|null $permanent_upazila_id
 * @property int|null $permanent_union_id
 * @property string|null $permanent_address_line
 * @property int $share_bps
 * @property int $sort
 * @property-read NomineeRelation|null $nomineeRelation
 */
#[UsePolicy(MemberApplicationNomineePolicy::class)]
final class MemberApplicationNominee extends Model
{
    protected $guarded = [];

    /**
     * The relation label is shown wherever a nominee is, and Livewire re-fetches records without nested eager loads.
     *
     * @var list<string>
     */
    protected $with = ['nomineeRelation'];

    /**
     * @return BelongsTo<NomineeRelation, $this>
     */
    public function nomineeRelation(): BelongsTo
    {
        return $this->belongsTo(NomineeRelation::class, 'relation_id');
    }

    public function share(): Bps
    {
        return Bps::of($this->share_bps);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['gender' => Gender::class, 'relation_id' => 'integer', 'share_bps' => 'integer', 'sort' => 'integer'];
    }
}
