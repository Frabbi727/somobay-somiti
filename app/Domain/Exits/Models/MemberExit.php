<?php

declare(strict_types=1);

namespace App\Domain\Exits\Models;

use App\Domain\Exits\Enums\ExitReason;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Members\Models\Member;
use App\Models\User;
use App\Policies\MemberExitPolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Time\YearMonth;
use App\Support\Time\YearMonthCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A member leaving (W9, BR-22). The approved settlement is fixed; history is kept forever.
 *
 * @property int $id
 * @property string $exit_no
 * @property int $member_id
 * @property ExitReason $reason_type
 * @property string $reason
 * @property YearMonth $exit_month
 * @property Money $exit_fee_poisha
 * @property ExitStatus $status
 * @property Money|null $savings_poisha
 * @property Money|null $advance_poisha
 * @property Money|null $dividends_poisha
 * @property Money|null $receivables_poisha
 * @property Money|null $released_poisha
 * @property Money|null $net_poisha
 * @property int|null $resolution_id
 * @property int $requested_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $settlement_journal_entry_id
 * @property int|null $paid_by
 * @property CarbonImmutable|null $paid_at
 * @property int|null $payout_journal_entry_id
 * @property string|null $cancel_reason
 * @property-read Member $member
 */
#[UsePolicy(MemberExitPolicy::class)]
final class MemberExit extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $with = ['member'];

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return HasMany<MemberExitPayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(MemberExitPayout::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('exits');
    }

    protected function casts(): array
    {
        return [
            'reason_type' => ExitReason::class,
            'status' => ExitStatus::class,
            'exit_month' => YearMonthCast::class,
            'exit_fee_poisha' => MoneyCast::class,
            'savings_poisha' => MoneyCast::class,
            'advance_poisha' => MoneyCast::class,
            'dividends_poisha' => MoneyCast::class,
            'receivables_poisha' => MoneyCast::class,
            'released_poisha' => MoneyCast::class,
            'net_poisha' => MoneyCast::class,
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }
}
