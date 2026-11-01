<?php

declare(strict_types=1);

namespace App\Domain\Members\Terms\Models;

use App\Domain\Members\Terms\Enums\TermsStatus;
use App\Domain\Shared\Exceptions\ImmutableRecord;
use App\Models\User;
use App\Policies\MembershipTermsPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of the somiti's rules and the member's declaration. The version number is given on
 * publishing; a published version never changes, a correction is a new version.
 *
 * @property int $id
 * @property int|null $version
 * @property TermsStatus $status
 * @property string $rules_bn
 * @property string $rules_en
 * @property string $declaration_bn
 * @property string $declaration_en
 * @property CarbonImmutable|null $published_at
 * @property int|null $published_by
 * @property int|null $created_by
 * @property-read User|null $publisher
 */
#[UsePolicy(MembershipTermsPolicy::class)]
final class MembershipTerms extends Model
{
    protected $table = 'membership_terms';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::updating(function (self $terms): void {
            if ($terms->getRawOriginal('status') === TermsStatus::Published->value) {
                throw ImmutableRecord::for(self::class, $terms->getKey());
            }
        });

        self::deleting(function (self $terms): void {
            if ($terms->status === TermsStatus::Published) {
                throw ImmutableRecord::for(self::class, $terms->getKey());
            }
        });
    }

    /**
     * The latest published version.
     */
    public static function current(): self
    {
        return self::query()
            ->where('status', TermsStatus::Published)
            ->orderByDesc('version')
            ->firstOrFail();
    }

    public static function draft(): ?self
    {
        return self::query()->where('status', TermsStatus::Draft)->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return HasMany<TermsAcceptance, $this>
     */
    public function acceptances(): HasMany
    {
        return $this->hasMany(TermsAcceptance::class, 'terms_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => TermsStatus::class,
            'published_at' => 'immutable_datetime',
        ];
    }
}
