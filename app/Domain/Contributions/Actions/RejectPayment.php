<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Actions;

use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The checker turns a pending payment down with a reason (W3). Nothing was posted, so nothing is reversed.
 */
final class RejectPayment
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Payment $payment, string $reason): Payment
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw DomainRuleViolation::because('payments.errors.reason_required');
        }

        return $this->causer->withCauser($actor, fn (): Payment => DB::transaction(function () use ($actor, $payment, $reason): Payment {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::Pending) {
                throw DomainRuleViolation::because('payments.errors.already_processed', ['status' => $locked->status->getLabel()]);
            }

            Gate::forUser($actor)->authorize('reject', $locked);

            $locked->forceFill([
                'status' => PaymentStatus::Rejected,
                'rejected_by' => $actor->id,
                'rejected_at' => CarbonImmutable::now(),
                'rejection_reason' => trim($reason),
            ])->save();

            return $locked;
        }, attempts: 3));
    }
}
