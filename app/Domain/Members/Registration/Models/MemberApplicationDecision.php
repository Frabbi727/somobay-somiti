<?php

declare(strict_types=1);

namespace App\Domain\Members\Registration\Models;

use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Models\User;
use App\Policies\MemberApplicationDecisionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One approver's decision on one step of one submission. Never changed afterwards.
 *
 * @property int $id
 * @property int $application_id
 * @property int $submission_no
 * @property int $step
 * @property Role $role
 * @property int $user_id
 * @property RegistrationDecisionType $decision
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 * @property-read User $user
 */
#[UsePolicy(MemberApplicationDecisionPolicy::class)]
final class MemberApplicationDecision extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /** @var list<string> */
    protected $with = ['user'];

    protected static function booted(): void
    {
        self::updating(fn (self $decision) => throw ImmutableRecord::for(self::class, $decision->getKey()));
        self::deleting(fn (self $decision) => throw ImmutableRecord::for(self::class, $decision->getKey()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'decision' => RegistrationDecisionType::class,
            'step' => 'integer',
            'submission_no' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
