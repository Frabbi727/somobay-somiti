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
use App\Domain\Investments\Enums\InvestmentTopUpStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Investments\Models\InvestmentTopUp;
use App\Domain\Investments\Services\InvestmentLimits;
use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Someone other than the recorder approves more money for an active investment: it goes out by
 * payment voucher (Dr the investment's 13xx account / Cr bank or cash) on its date and is added to
 * the investment's register, so the book value and the 13xx account rise together.
 */
final class ApproveInvestmentTopUp
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly FundsGuard $funds,
        private readonly RequiredResolutions $resolutions,
        private readonly InvestmentLimits $limits,
        private readonly CauserResolver $causer,
        private readonly WorkflowNotifier $notifier,
    ) {}

    public function __invoke(User $actor, InvestmentTopUp $topUp): InvestmentTopUp
    {
        return $this->causer->withCauser($actor, fn (): InvestmentTopUp => DB::transaction(function () use ($actor, $topUp): InvestmentTopUp {
            $investment = Investment::query()->whereKey($topUp->investment_id)->lockForUpdate()->firstOrFail();
            $locked = InvestmentTopUp::query()->whereKey($topUp->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvestmentTopUpStatus::Pending) {
                throw DomainRuleViolation::because('investments.errors.top_up_not_pending', ['number' => $investment->investment_no]);
            }

            Gate::forUser($actor)->authorize('approve', $locked);

            if ($investment->status !== InvestmentStatus::Active) {
                throw DomainRuleViolation::because('investments.errors.top_up_not_active', ['number' => $investment->investment_no]);
            }

            $this->resolutions->assertSatisfied(ResolutionSubject::Investment, $locked->resolution_id);

            $warnings = $this->limits->warningsFor($investment->type, $locked->amount_poisha);
            $source = $this->accounts->byCode($locked->funded_from->accountCode());
            $this->funds->assertCovers($source, $locked->amount_poisha, $locked->invested_on);

            $entry = ($this->post)($actor, new JournalEntryData(
                type: VoucherType::Payment,
                entryDate: $locked->invested_on,
                narration: sprintf('%s: %s — %s', $investment->investment_no, __('investments.top_up.narration'), $investment->displayName()),
                lines: [
                    JournalLineData::debit(Account::query()->findOrFail($investment->account_id), $locked->amount_poisha),
                    JournalLineData::credit($source, $locked->amount_poisha),
                ],
                source: $locked,
            ));

            $investment->ledger()->create([
                'kind' => InvestmentEntryKind::TopUp,
                'delta_poisha' => $locked->amount_poisha,
                'journal_entry_id' => $entry->id,
                'created_by' => $actor->id,
            ]);

            $locked->update([
                'status' => InvestmentTopUpStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now(),
                'journal_entry_id' => $entry->id,
                'limit_warnings' => $warnings === [] ? null : implode("\n", $warnings),
            ]);

            $this->notifier->outcome($locked, 'approved', [$locked->recorded_by]);

            return $locked->setRelation('journalEntry', $entry)->setRelation('investment', $investment);
        }, attempts: 3));
    }
}
