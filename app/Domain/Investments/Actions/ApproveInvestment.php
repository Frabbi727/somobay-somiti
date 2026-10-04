<?php

declare(strict_types=1);

namespace App\Domain\Investments\Actions;

use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Accounting\Services\FundsGuard;
use App\Domain\Governance\Enums\ResolutionSubject;
use App\Domain\Governance\Services\RequiredResolutions;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Services\InvestmentLimits;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W7: the president approves; the money goes out by payment voucher (Dr 13xx / Cr bank or cash)
 * on the investment date and the register records the principal. s.33 limit warnings are stored
 * with the investment; a linked resolution is required where configured.
 */
final class ApproveInvestment
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly FundsGuard $funds,
        private readonly RequiredResolutions $resolutions,
        private readonly InvestmentLimits $limits,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, Investment $investment): Investment
    {
        return $this->causer->withCauser($actor, fn (): Investment => DB::transaction(function () use ($actor, $investment): Investment {
            $locked = Investment::query()->whereKey($investment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvestmentStatus::Pending) {
                throw DomainRuleViolation::because('investments.errors.not_pending', ['number' => $locked->investment_no]);
            }

            Gate::forUser($actor)->authorize('approve', $locked);
            $this->resolutions->assertSatisfied(ResolutionSubject::Investment, $locked->resolution_id);

            $warnings = $this->limits->warningsFor($locked->type, $locked->principal_poisha);
            $source = $this->accounts->byCode($locked->funded_from->accountCode());
            $this->funds->assertCovers($source, $locked->principal_poisha);

            $entry = ($this->post)($actor, new JournalEntryData(
                type: VoucherType::Payment,
                entryDate: $locked->invested_on,
                narration: trim(sprintf('%s: %s — %s %s', $locked->investment_no, $locked->type->getLabel(), $locked->institution, $locked->instrument_no ?? '')),
                lines: [
                    JournalLineData::debit(Account::query()->findOrFail($locked->account_id), $locked->principal_poisha),
                    JournalLineData::credit($source, $locked->principal_poisha),
                ],
                source: $locked,
            ));

            $locked->ledger()->create([
                'kind' => InvestmentEntryKind::Disbursement,
                'delta_poisha' => $locked->principal_poisha,
                'journal_entry_id' => $entry->id,
                'created_by' => $actor->id,
            ]);

            $locked->update([
                'status' => InvestmentStatus::Active,
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now(),
                'journal_entry_id' => $entry->id,
                'limit_warnings' => $warnings === [] ? null : implode("\n", $warnings),
            ]);

            return $locked->setRelation('journalEntry', $entry);
        }, attempts: 3));
    }
}
