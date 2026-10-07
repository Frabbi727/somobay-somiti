<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Models;

use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Data\NomineeData;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationNextAction;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Models\User;
use App\Policies\MemberApplicationPolicy;
use App\Support\Money\Bps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A member's own registration: their draft details and nominees, where it is in the approval chain,
 * and — once approved — the member it became. Never deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property string $mobile
 * @property MemberApplicationStatus $status
 * @property string|null $name_bn
 * @property string|null $name_en
 * @property string|null $guardian_name
 * @property string|null $nid
 * @property CarbonImmutable|null $date_of_birth
 * @property string|null $email
 * @property string|null $address
 * @property string|null $photo_path
 * @property int|null $requested_shares
 * @property list<string>|null $approval_chain
 * @property int|null $current_step
 * @property int $submission_no
 * @property string|null $submit_idempotency_key
 * @property int $invited_by
 * @property int|null $member_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Collection<int, MemberApplicationNominee> $nominees
 * @property-read Collection<int, MemberApplicationDecision> $decisions
 */
#[UsePolicy(MemberApplicationPolicy::class)]
final class MemberApplication extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::deleting(fn (self $application) => throw ImmutableRecord::for(self::class, $application->getKey()));
    }

    public static function openForMobile(string $mobile): ?self
    {
        return self::query()->where('mobile', $mobile)->whereIn('status', MemberApplicationStatus::openValues())->first();
    }

    /**
     * Registrations whose current step is for one of the user's roles.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWaitingFor(Builder $query, User $user): void
    {
        $roles = $user->getRoleNames()->all();

        $query->where('status', MemberApplicationStatus::Submitted)
            ->whereRaw('(approval_chain ->> current_step) = ANY(?::text[])', ['{'.implode(',', $roles).'}']);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return HasMany<MemberApplicationNominee, $this>
     */
    public function nominees(): HasMany
    {
        return $this->hasMany(MemberApplicationNominee::class, 'application_id')->orderBy('sort');
    }

    /**
     * @return HasMany<MemberApplicationDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(MemberApplicationDecision::class, 'application_id')->orderBy('id');
    }

    /**
     * The approval steps of the current submission (snapshot taken at submit).
     *
     * @return list<Role>
     */
    public function chain(): array
    {
        return array_map(fn (string $role): Role => Role::from($role), $this->approval_chain ?? []);
    }

    public function currentRole(): ?Role
    {
        return $this->status === MemberApplicationStatus::Submitted && $this->current_step !== null
            ? ($this->chain()[$this->current_step] ?? null)
            : null;
    }

    public function isLastStep(): bool
    {
        return $this->current_step !== null && $this->current_step === count($this->chain()) - 1;
    }

    /**
     * @return Collection<int, MemberApplicationDecision>
     */
    public function currentDecisions(): Collection
    {
        return $this->decisions()->where('submission_no', $this->submission_no)->get();
    }

    public function hasDecidedThisSubmission(User $user): bool
    {
        return $this->decisions()->where('submission_no', $this->submission_no)->where('user_id', $user->id)->exists();
    }

    public function nextAction(): RegistrationNextAction
    {
        return match ($this->status) {
            MemberApplicationStatus::Invited => RegistrationNextAction::Complete,
            MemberApplicationStatus::Returned => RegistrationNextAction::Resubmit,
            MemberApplicationStatus::Submitted => RegistrationNextAction::Wait,
            MemberApplicationStatus::Approved, MemberApplicationStatus::Rejected => RegistrationNextAction::None,
        };
    }

    /**
     * The details as the office form would have entered them, for MemberRules and CreateMember.
     */
    public function toMemberData(CarbonImmutable $joinedOn): MemberData
    {
        return new MemberData(
            nameBn: (string) $this->name_bn,
            nameEn: (string) $this->name_en,
            mobile: $this->mobile,
            joinedOn: $joinedOn,
            guardianName: $this->guardian_name,
            nid: $this->nid,
            dateOfBirth: $this->date_of_birth,
            email: $this->email,
            address: $this->address,
            photoPath: $this->photo_path,
            nominees: array_values($this->nominees->map(fn (MemberApplicationNominee $nominee): NomineeData => new NomineeData(
                name: $nominee->name,
                relation: '',
                share: Bps::of($nominee->share_bps),
                mobile: $nominee->mobile,
                nid: $nominee->nid,
                relationId: $nominee->relation_id,
            ))->all()),
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('members');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MemberApplicationStatus::class,
            'date_of_birth' => 'immutable_date',
            'approval_chain' => 'array',
            'requested_shares' => 'integer',
            'current_step' => 'integer',
            'submission_no' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
