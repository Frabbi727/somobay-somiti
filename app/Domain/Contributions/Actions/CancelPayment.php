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
 * The person who recorded a payment withdraws it while it is still pending (§7.4).
 */
final class CancelPayment
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, Payment $payment): Payment
    {
        return $this->causer->withCauser($actor, fn (): Payment => DB::transaction(function () use ($actor, $payment): Payment {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::Pending) {
                throw DomainRuleViolation::because('payments.errors.already_processed', ['status' => $locked->status->getLabel()]);
            }

            Gate::forUser($actor)->authorize('cancel', $locked);

            $locked->forceFill(['status' => PaymentStatus::Cancelled, 'cancelled_at' => CarbonImmutable::now()])->save();

            return $locked;
        }, attempts: 3));
    }
}
