<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Actions;

use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W3 (maker): records money received as a pending payment. Nothing is posted until a different
 * user approves it. Submitting the same idempotency key twice returns the first payment.
 */
final class RecordPayment
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, PaymentData $data): Payment
    {
        Gate::forUser($actor)->authorize('create', Payment::class);

        // One submission per idempotency key at a time: a retry that arrives while the first is still
        // running waits here, then finds and returns the same payment instead of colliding.
        return $this->causer->withCauser($actor, fn (): Payment => DB::transaction(function () use ($actor, $data): Payment {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['payment:'.$data->idempotencyKey]);

            $existing = Payment::query()->where('idempotency_key', $data->idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->member_id !== $data->memberId || ! $existing->amount_poisha->equals($data->amount)) {
                    throw DomainRuleViolation::because('payments.errors.idempotency_conflict');
                }

                return $existing;
            }

            $this->assertValid($data);
            $this->assertMemberSubmission($actor, $data);

            return Payment::query()->create([
                'member_id' => $data->memberId,
                'method' => $data->method,
                'amount_poisha' => $data->amount,
                'trx_id' => $data->method->needsTrxId() ? $data->trxId : null,
                'proof_path' => $data->proofPath,
                'received_on' => $data->receivedOn->toDateString(),
                'status' => PaymentStatus::Pending,
                'idempotency_key' => $data->idempotencyKey,
                'notes' => $data->notes,
                'recorded_by' => $actor->id,
            ]);
        }, attempts: 3));
    }

    /**
     * A member may only report their own bKash/Nagad payment. Proof is optional: staff approve every
     * member submission and can check the TrxID against the bKash/Nagad statement.
     */
    private function assertMemberSubmission(User $actor, PaymentData $data): void
    {
        if ($actor->isStaff()) {
            return;
        }

        $own = Member::query()->where('user_id', $actor->id)->value('id');

        if ($own === null || (int) $own !== $data->memberId) {
            throw new AuthorizationException;
        }

        if (! in_array($data->method, [PaymentMethod::Bkash, PaymentMethod::Nagad], true)) {
            throw DomainRuleViolation::because('payments.errors.member_submission');
        }
    }

    private function assertValid(PaymentData $data): void
    {
        $member = Member::query()->find($data->memberId);

        if ($member === null || $member->status === MemberStatus::Exited) {
            throw DomainRuleViolation::because('payments.errors.member_unavailable');
        }

        if (! $data->amount->isPositive()) {
            throw DomainRuleViolation::because('payments.errors.amount_positive');
        }

        if ($data->method->needsTrxId() && ($data->trxId === null || preg_match('/^[A-Z0-9]{6,40}$/', $data->trxId) !== 1)) {
            throw DomainRuleViolation::because('payments.errors.trx_required', ['method' => $data->method->getLabel()]);
        }

        if ($data->method->needsTrxId() && Payment::query()->where('method', $data->method)->where('trx_id', $data->trxId)->exists()) {
            throw DomainRuleViolation::because('payments.errors.trx_taken', ['trx' => (string) $data->trxId]);
        }

        if ($data->receivedOn->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay())) {
            throw DomainRuleViolation::because('payments.errors.future_date');
        }
    }
}
