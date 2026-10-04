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

/**
 * The maker withdraws their own pending transfer (e.g. entered twice).
 */
final class CancelFundTransfer
{
    public function __construct(private readonly CauserResolver $causer) {}

    public function __invoke(User $actor, FundTransfer $transfer): FundTransfer
    {
        return $this->causer->withCauser($actor, fn (): FundTransfer => DB::transaction(function () use ($actor, $transfer): FundTransfer {
            $locked = FundTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TransferStatus::Pending) {
                throw DomainRuleViolation::because('transfers.errors.not_pending', ['number' => $locked->transfer_no]);
            }

            Gate::forUser($actor)->authorize('cancel', $locked);

            $locked->update(['status' => TransferStatus::Cancelled]);

            return $locked;
        }, attempts: 3));
    }
}
