<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Models\FundTransfer;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Accounting\Services\FundsGuard;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Undoes an approved transfer with a mirror voucher (the money came back, or it was entered wrongly).
 */
final class ReverseFundTransfer
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly Accounts $accounts,
        private readonly FundsGuard $funds,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, FundTransfer $transfer, string $reason): FundTransfer
    {
        if (mb_strlen(trim($reason)) < ReverseJournal::MIN_REASON_LENGTH) {
            throw DomainRuleViolation::because('journal.errors.reason_required', ['min' => ReverseJournal::MIN_REASON_LENGTH]);
        }

        return $this->causer->withCauser($actor, fn (): FundTransfer => DB::transaction(function () use ($actor, $transfer, $reason): FundTransfer {
            $locked = FundTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TransferStatus::Approved) {
                throw DomainRuleViolation::because('transfers.errors.not_approved', ['number' => $locked->transfer_no]);
            }

            Gate::forUser($actor)->authorize('reverse', $locked);

            // The money goes back out of the destination, which must still hold it.
            $this->funds->assertCovers($this->accounts->byCode($locked->to_method->accountCode()), $locked->amount_poisha);

            $reversal = ($this->reverseJournal)(
                $actor,
                JournalEntry::query()->findOrFail($locked->journal_entry_id),
                $reason,
                onBehalfOfOwner: true,
            );

            $locked->update([
                'status' => TransferStatus::Reversed,
                'reversed_by' => $actor->id,
                'reversed_at' => CarbonImmutable::now(),
                'reversal_reason' => trim($reason),
                'reversal_journal_entry_id' => $reversal->id,
            ]);

            return $locked;
        }, attempts: 3));
    }
}
