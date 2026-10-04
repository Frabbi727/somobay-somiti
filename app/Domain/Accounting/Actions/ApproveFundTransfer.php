<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\FundTransfer;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Accounting\Services\FundsGuard;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Checker: posts a contra voucher — Dr destination / Cr source, plus Dr 5104 for any charge.
 * The source must hold the amount and the charge.
 */
final class ApproveFundTransfer
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly FundsGuard $funds,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, FundTransfer $transfer): FundTransfer
    {
        return $this->causer->withCauser($actor, fn (): FundTransfer => DB::transaction(function () use ($actor, $transfer): FundTransfer {
            $locked = FundTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TransferStatus::Pending) {
                throw DomainRuleViolation::because('transfers.errors.not_pending', ['number' => $locked->transfer_no]);
            }

            Gate::forUser($actor)->authorize('approve', $locked);

            $source = $this->accounts->byCode($locked->from_method->accountCode());
            $destination = $this->accounts->byCode($locked->to_method->accountCode());

            $this->funds->assertCovers($source, $locked->outflow());

            $lines = [JournalLineData::debit($destination, $locked->amount_poisha)];

            if ($locked->charge_poisha->isPositive()) {
                $lines[] = JournalLineData::debit($this->accounts->byCode(AccountCode::BANK_CHARGES), $locked->charge_poisha);
            }

            $lines[] = JournalLineData::credit($source, $locked->outflow());

            $entry = ($this->post)($actor, new JournalEntryData(
                type: VoucherType::Contra,
                entryDate: $locked->transferred_on,
                narration: trim(sprintf('%s: %s → %s %s', $locked->transfer_no, $locked->from_method->getLabel(), $locked->to_method->getLabel(), $locked->reference ?? '')),
                lines: $lines,
                source: $locked,
            ));

            $locked->update([
                'status' => TransferStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now(),
                'journal_entry_id' => $entry->id,
            ]);

            return $locked->setRelation('journalEntry', $entry);
        }, attempts: 3));
    }
}
