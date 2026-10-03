<?php

declare(strict_types=1);

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Enums\ApprovalDecision;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Enums\Role;
use App\Models\User;
use App\Policies\RatePlanApprovalPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One committee member's decision on one submission of a rate plan. Never changed afterwards.
 *
 * @property int $id
 * @property int $rate_plan_id
 * @property int $submission_no
 * @property int $user_id
 * @property Role $role
 * @property ApprovalDecision $decision
 * @property string|null $comment
 * @property CarbonImmutable $created_at
 * @property-read User $user
 */
#[UsePolicy(RatePlanApprovalPolicy::class)]
final class RatePlanApproval extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * Approvals are always shown with who decided.
     *
     * @var list<string>
     */
    protected $with = ['user'];

    protected static function booted(): void
    {
        self::updating(fn (self $approval) => throw ImmutableRecord::for(self::class, $approval->getKey()));
        self::deleting(fn (self $approval) => throw ImmutableRecord::for(self::class, $approval->getKey()));
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
            'decision' => ApprovalDecision::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
