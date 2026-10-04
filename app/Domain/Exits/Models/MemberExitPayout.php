<?php

declare(strict_types=1);

namespace App\Domain\Exits\Models;

use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Members\Models\Nominee;
use App\Policies\MemberExitPayoutPolicy;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who received what from an exit settlement (the member, or each nominee by share_bps).
 *
 * @property int $id
 * @property int $member_exit_id
 * @property int|null $nominee_id
 * @property string $payee
 * @property int $share_bps
 * @property Money $amount_poisha
 * @property PaymentMethod $paid_from
 */
#[UsePolicy(MemberExitPayoutPolicy::class)]
final class MemberExitPayout extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return BelongsTo<Nominee, $this>
     */
    public function nominee(): BelongsTo
    {
        return $this->belongsTo(Nominee::class);
    }

    protected function casts(): array
    {
        return [
            'amount_poisha' => MoneyCast::class,
            'paid_from' => PaymentMethod::class,
            'share_bps' => 'integer',
        ];
    }
}
