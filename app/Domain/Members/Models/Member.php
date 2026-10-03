<?php

declare(strict_types=1);

namespace App\Domain\Members\Models;

use App\Domain\Contributions\Models\Due;
use App\Domain\Members\Enums\MemberStatus;
use App\Models\User;
use App\Policies\MemberPolicy;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A somiti member (সদস্য). Never hard-deleted (§7.4); leaving is an exit, pausing is deactivation.
 *
 * @property int $id
 * @property string $member_no
 * @property string $name_bn
 * @property string $name_en
 * @property string|null $guardian_name
 * @property string|null $nid
 * @property CarbonImmutable|null $date_of_birth
 * @property string $mobile
 * @property string|null $email
 * @property string|null $address
 * @property string|null $photo_path
 * @property MemberStatus $status
 * @property CarbonImmutable $joined_on
 * @property CarbonImmutable|null $deactivated_at
 * @property string|null $deactivation_reason
 * @property int|null $user_id
 * @property int $created_by
 */
#[UseFactory(MemberFactory::class)]
#[UsePolicy(MemberPolicy::class)]
final class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $guarded = [];

    /**
     * @return HasMany<Nominee, $this>
     */
    public function nominees(): HasMany
    {
        return $this->hasMany(Nominee::class)->orderBy('sort');
    }

    /**
     * @return HasMany<ShareLot, $this>
     */
    public function shareLots(): HasMany
    {
        return $this->hasMany(ShareLot::class)->orderBy('effective_from')->orderBy('id');
    }

    /**
     * @return HasMany<ShareTransaction, $this>
     */
    public function shareTransactions(): HasMany
    {
        return $this->hasMany(ShareTransaction::class)->orderBy('id');
    }

    /**
     * @return HasMany<MemberShareSnapshot, $this>
     */
    public function shareSnapshots(): HasMany
    {
        return $this->hasMany(MemberShareSnapshot::class)->orderBy('effective_from');
    }

    /**
     * @return HasMany<Due, $this>
     */
    public function dues(): HasMany
    {
        return $this->hasMany(Due::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active;
    }

    /**
     * Shares held in a month, from the share timeline.
     */
    public function sharesIn(YearMonth $month): int
    {
        return (int) MemberShareSnapshot::query()
            ->where('member_id', $this->id)
            ->where('effective_from', '<=', $month->toDateString())
            ->orderByDesc('effective_from')
            ->value('shares');
    }

    /**
     * "M-0007 · রহিম উদ্দিন" in the current locale.
     */
    public function displayName(): string
    {
        return $this->member_no.' · '.(app()->getLocale() === 'bn' ? $this->name_bn : $this->name_en);
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
            'status' => MemberStatus::class,
            'date_of_birth' => 'immutable_date',
            'joined_on' => 'immutable_date',
            'deactivated_at' => 'immutable_datetime',
        ];
    }
}
