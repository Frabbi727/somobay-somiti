<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Models\FundTransfer;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

final class RejectFundTransfer
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, FundTransfer $transfer, string $reason): FundTransfer
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): FundTransfer => DB::transaction(function () use ($actor, $transfer, $reason): FundTransfer {
            $locked = FundTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TransferStatus::Pending) {
                throw DomainRuleViolation::because('transfers.errors.not_pending', ['number' => $locked->transfer_no]);
            }

            Gate::forUser($actor)->authorize('reject', $locked);

            $locked->update(['status' => TransferStatus::Rejected, 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);

            return $locked;
        }, attempts: 3));
    }
}
