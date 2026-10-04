<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Actions;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Accounting\Services\FundsGuard;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Enums\DividendSettlement;
use App\Domain\YearEnd\Enums\DividendStatus;
use App\Domain\YearEnd\Models\DividendLine;
use App\Models\User;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Settles one member's dividend, once: paid out (PV Dr 2201 / Cr cash or bank) or credited to the
 * member's savings (JV Dr 2201 / Cr 2101), per SOMITI_SPEC.md Phase 11.
 */
final class SettleDividend
{
    public function __construct(
        private readonly PostJournal $post,
        private readonly Accounts $accounts,
        private readonly FundsGuard $funds,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, DividendLine $line, DividendSettlement $how, ?PaymentMethod $paidFrom = null, ?CarbonImmutable $on = null): DividendLine
    {
        $on ??= CarbonImmutable::now(YearMonth::TIMEZONE)->startOfDay();

        return $this->causer->withCauser($actor, fn (): DividendLine => DB::transaction(function () use ($actor, $line, $how, $paidFrom, $on): DividendLine {
            // Lock order: member → the line (§3.2).
            Member::query()->whereKey($line->member_id)->lockForUpdate()->firstOrFail();
            $locked = DividendLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== DividendStatus::Unpaid) {
                throw DomainRuleViolation::because('year_end.errors.already_settled');
            }

            Gate::forUser($actor)->authorize('settle', $locked);

            if ($on->isAfter(CarbonImmutable::now(YearMonth::TIMEZONE)->endOfDay())) {
                throw DomainRuleViolation::because('year_end.errors.future_date');
            }

            $payable = JournalLineData::debit($this->accounts->byCode(AccountCode::DIVIDEND_PAYABLE), $locked->amount_poisha, $locked->member_id);

            if ($how === DividendSettlement::Payout) {
                $source = $this->accounts->byCode(($paidFrom ?? PaymentMethod::Cash)->accountCode());
                $this->funds->assertCovers($source, $locked->amount_poisha);
                $other = JournalLineData::credit($source, $locked->amount_poisha);
            } else {
                $other = JournalLineData::credit($this->accounts->byCode(AccountCode::MEMBER_SAVINGS), $locked->amount_poisha, $locked->member_id);
            }

            $entry = ($this->post)($actor, new JournalEntryData(
                type: $how === DividendSettlement::Payout ? VoucherType::Payment : VoucherType::Journal,
                entryDate: $on,
                narration: __('year_end.narration.dividend_'.$how->value, ['code' => $locked->yearEnd->fiscalYear->code]),
                lines: [$payable, $other],
                source: $locked,
            ));

            $locked->update([
                'status' => $how->status(),
                'settled_via' => $how === DividendSettlement::Payout ? ($paidFrom ?? PaymentMethod::Cash)->value : 'savings',
                'settlement_journal_entry_id' => $entry->id,
                'settled_by' => $actor->id,
                'settled_at' => CarbonImmutable::now(),
            ]);

            return $locked;
        }, attempts: 3));
    }
}
