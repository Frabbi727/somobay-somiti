<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use App\Domain\Governance\Models\Resolution;
use App\Domain\Settings\Enums\AdvancePolicy;
use App\Domain\Settings\Enums\ApprovalDecision;
use App\Domain\Settings\Enums\LateFeeBase;
use App\Domain\Settings\Enums\LateFeeFrequency;
use App\Domain\Settings\Enums\LateFeeMode;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Enums\RegistrationFeePolicy;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Models\User;
use App\Policies\RatePlanPolicy;
use App\Support\Money\Bps;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One version of the somiti's rates, applying from effective_from until a later approved
 * plan starts (BR-5). Approved plans never change (BR-6); corrections are new versions.
 *
 * @property int|null $resolution_id
 * @property int $id
 * @property string $code
 * @property YearMonth $effective_from
 * @property RatePlanStatus $status
 * @property bool $is_retroactive
 * @property Money $share_unit_poisha
 * @property Money $service_charge_per_share_poisha
 * @property Money $registration_fee_per_share_poisha
 * @property int $due_day
 * @property int $grace_days
 * @property LateFeeMode $late_fee_mode
 * @property Money|null $late_fee_fixed_poisha
 * @property int|null $late_fee_bps
 * @property LateFeeBase|null $late_fee_base
 * @property Money|null $late_fee_cap_poisha
 * @property LateFeeFrequency|null $late_fee_frequency
 * @property AdvancePolicy $advance_policy
 * @property RegistrationFeePolicy $registration_fee_on_rate_increase
 * @property list<string> $allocation_order
 * @property string|null $notes
 * @property int $submission_no
 * @property int $created_by
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_reason
 * @property int|null $supersedes_id
 * @property-read User $creator
 */
#[UsePolicy(RatePlanPolicy::class)]
final class RatePlan extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * Attributes that may still change after approval: only the move to cancelled/superseded.
     */
    private const array CLOSING_ATTRIBUTES = ['status', 'cancelled_at', 'cancelled_reason', 'updated_at'];

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (self $plan): void {
            $original = RatePlanStatus::from((string) $plan->getRawOriginal('status'));

            if (in_array($original, [RatePlanStatus::Cancelled, RatePlanStatus::Superseded], true)) {
                throw ImmutableRecord::for(self::class, $plan->getKey());
            }

            if ($original === RatePlanStatus::Approved && array_diff(array_keys($plan->getDirty()), self::CLOSING_ATTRIBUTES) !== []) {
                throw ImmutableRecord::for(self::class, $plan->getKey());
            }
        });

        self::deleting(function (self $plan): void {
            if (! in_array($plan->status, [RatePlanStatus::Draft, RatePlanStatus::PendingApproval], true)) {
                throw ImmutableRecord::for(self::class, $plan->getKey());
            }
        });
    }

    /**
     * @return HasMany<RatePlanApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(RatePlanApproval::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Roles that approved the current submission.
     *
     * @return list<Role>
     */
    public function approvedRoles(): array
    {
        return array_values(array_unique(array_map(
            fn (RatePlanApproval $approval): Role => $approval->role,
            $this->approvals()
                ->where('submission_no', $this->submission_no)
                ->where('decision', ApprovalDecision::Approve)
                ->get()
                ->all(),
        ), SORT_REGULAR));
    }

    public function hasDecided(User $user): bool
    {
        return $this->approvals()
            ->where('submission_no', $this->submission_no)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function lateFeeBps(): ?Bps
    {
        return $this->late_fee_bps === null ? null : Bps::of($this->late_fee_bps);
    }

    /**
     * The resolution that adopted this plan (required when somiti.require_resolution_for lists rate_plan).
     *
     * @return BelongsTo<Resolution, $this>
     */
    public function resolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class);
    }

    /**
     * Everything a due must copy from its plan (BR-9), so later plans never change old dues.
     *
     * @return array<string, int|string|null|list<string>>
     */
    public function snapshot(): array
    {
        return [
            'rate_plan_id' => $this->id,
            'rate_plan_code' => $this->code,
            'share_unit_poisha' => $this->share_unit_poisha->poisha,
            'service_charge_per_share_poisha' => $this->service_charge_per_share_poisha->poisha,
            'registration_fee_per_share_poisha' => $this->registration_fee_per_share_poisha->poisha,
            'due_day' => $this->due_day,
            'grace_days' => $this->grace_days,
            'late_fee_mode' => $this->late_fee_mode->value,
            'late_fee_fixed_poisha' => $this->late_fee_fixed_poisha?->poisha,
            'late_fee_bps' => $this->late_fee_bps,
            'late_fee_base' => $this->late_fee_base?->value,
            'late_fee_cap_poisha' => $this->late_fee_cap_poisha?->poisha,
            'late_fee_frequency' => $this->late_fee_frequency?->value,
            'advance_policy' => $this->advance_policy->value,
            'allocation_order' => $this->allocation_order,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('settings');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => YearMonthCast::class,
            'status' => RatePlanStatus::class,
            'is_retroactive' => 'boolean',
            'share_unit_poisha' => MoneyCast::class,
            'service_charge_per_share_poisha' => MoneyCast::class,
            'registration_fee_per_share_poisha' => MoneyCast::class,
            'due_day' => 'integer',
            'grace_days' => 'integer',
            'late_fee_mode' => LateFeeMode::class,
            'late_fee_fixed_poisha' => MoneyCast::class,
            'late_fee_bps' => 'integer',
            'late_fee_base' => LateFeeBase::class,
            'late_fee_cap_poisha' => MoneyCast::class,
            'late_fee_frequency' => LateFeeFrequency::class,
            'advance_policy' => AdvancePolicy::class,
            'registration_fee_on_rate_increase' => RegistrationFeePolicy::class,
            'allocation_order' => 'array',
            'submission_no' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
