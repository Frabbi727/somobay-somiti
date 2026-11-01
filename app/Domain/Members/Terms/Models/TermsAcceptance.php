<?php

declare(strict_types=1);

namespace App\Domain\Members\Terms\Models;

use App\Domain\Members\Terms\Enums\ConsentChannel;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Models\User;
use App\Policies\TermsAcceptancePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof that a member (or an applicant) accepted one version of the rules. Insert-only.
 *
 * @property int $id
 * @property int $terms_id
 * @property int|null $member_id
 * @property int|null $application_id
 * @property int $user_id
 * @property ConsentChannel $channel
 * @property string|null $ip
 * @property string $idempotency_key
 * @property CarbonImmutable $accepted_at
 * @property-read MembershipTerms $terms
 */
#[UsePolicy(TermsAcceptancePolicy::class)]
final class TermsAcceptance extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(fn (self $acceptance) => throw ImmutableRecord::for(self::class, $acceptance->getKey()));
        self::deleting(fn (self $acceptance) => throw ImmutableRecord::for(self::class, $acceptance->getKey()));
    }

    /**
     * @return BelongsTo<MembershipTerms, $this>
     */
    public function terms(): BelongsTo
    {
        return $this->belongsTo(MembershipTerms::class, 'terms_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'channel' => ConsentChannel::class,
            'accepted_at' => 'immutable_datetime',
        ];
    }
}
