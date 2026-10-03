<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\Period;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Domain\Accounting\Services\JournalHasher;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The single way anything enters the journal (BR-17).
 *
 * Rules: at least two lines; each line is one-sided and positive; debits equal credits;
 * accounts exist and are active; member_id is given exactly when the account requires it;
 * the entry date falls in an open period of an open fiscal year.
 *
 * Authorisation belongs to the calling action (approve payment, post draft, reverse, …).
 */
final class PostJournal
{
    public function __construct(
        private readonly NextVoucherNumber $numbers,
        private readonly JournalHasher $hasher,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, JournalEntryData $data, ?JournalEntry $reverses = null, bool $allowInactiveAccounts = false): JournalEntry
    {
        return $this->causer->withCauser($actor, fn (): JournalEntry => DB::transaction(
            fn (): JournalEntry => $this->post($actor, $data, $reverses, $allowInactiveAccounts),
            attempts: 3,
        ));
    }

    private function post(User $actor, JournalEntryData $data, ?JournalEntry $reverses, bool $allowInactiveAccounts): JournalEntry
    {
        if (trim($data->narration) === '') {
            throw DomainRuleViolation::because('journal.errors.narration_required');
        }

        $this->assertLinesBalance($data->lines);
        $this->assertAccountsUsable($data->lines, $allowInactiveAccounts);
        [$fiscalYear, $period] = $this->openPeriodFor($data->entryDate);

        $voucher = ($this->numbers)($fiscalYear, $data->type);

        $entryAttributes = [
            'voucher_no' => $voucher->number,
            'voucher_type' => $data->type->value,
            'entry_date' => $data->entryDate->toDateString(),
            'narration' => trim($data->narration),
            'reason' => $data->reason,
            'reverses_id' => $reverses?->id,
        ];

        $lineAttributes = [];

        foreach ($data->lines as $index => $line) {
            $lineAttributes[] = [
                'line_no' => $index + 1,
                'account_id' => $line->accountId,
                'member_id' => $line->memberId,
                'debit_poisha' => $line->debit->poisha,
                'credit_poisha' => $line->credit->poisha,
                'memo' => $line->memo,
            ];
        }

        $hash = $this->hasher->hash($voucher->previousHash, $entryAttributes, $lineAttributes);

        $entry = JournalEntry::query()->create([
            ...$entryAttributes,
            'fiscal_year_id' => $fiscalYear->id,
            'period_id' => $period->id,
            'source_type' => $data->source?->getMorphClass(),
            'source_id' => $data->source?->getKey(),
            'posted_by' => $actor->id,
            'posted_at' => CarbonImmutable::now(),
            'hash' => $hash,
        ]);

        foreach ($lineAttributes as $line) {
            $entry->lines()->create([
                ...$line,
                'debit_poisha' => Money::ofPoisha($line['debit_poisha']),
                'credit_poisha' => Money::ofPoisha($line['credit_poisha']),
            ]);
        }

        $this->numbers->recordHash($fiscalYear, $data->type, $hash);

        return $entry->load('lines');
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function assertLinesBalance(array $lines): void
    {
        if (count($lines) < 2) {
            throw DomainRuleViolation::because('journal.errors.too_few_lines');
        }

        $debits = Money::zero();
        $credits = Money::zero();

        foreach ($lines as $index => $line) {
            $oneSided = ($line->debit->isPositive() && $line->credit->isZero())
                || ($line->debit->isZero() && $line->credit->isPositive());

            if (! $oneSided) {
                throw DomainRuleViolation::because('journal.errors.one_sided', ['line' => $index + 1]);
            }

            $debits = $debits->plus($line->debit);
            $credits = $credits->plus($line->credit);
        }

        if (! $debits->equals($credits)) {
            throw DomainRuleViolation::because('journal.errors.unbalanced', [
                'debit' => $debits->format(app()->getLocale()),
                'credit' => $credits->format(app()->getLocale()),
            ]);
        }
    }

    /**
     * @param  list<JournalLineData>  $lines
     */
    private function assertAccountsUsable(array $lines, bool $allowInactive): void
    {
        $accounts = Account::query()
            ->whereIn('id', array_unique(array_map(fn (JournalLineData $line): int => $line->accountId, $lines)))
            ->get()
            ->keyBy('id');

        $memberIds = array_values(array_unique(array_filter(array_map(fn (JournalLineData $line): ?int => $line->memberId, $lines))));
        $knownMembers = $memberIds === [] ? [] : Member::query()->whereIn('id', $memberIds)->pluck('id')->all();

        foreach ($lines as $index => $line) {
            $account = $accounts->get($line->accountId);

            if ($line->memberId !== null && ! in_array($line->memberId, $knownMembers, true)) {
                throw DomainRuleViolation::because('journal.errors.unknown_member', ['line' => $index + 1]);
            }

            if ($account === null) {
                throw DomainRuleViolation::because('journal.errors.unknown_account', ['line' => $index + 1]);
            }

            if (! $account->is_active && ! $allowInactive) {
                throw DomainRuleViolation::because('journal.errors.inactive_account', ['code' => $account->code]);
            }

            if ($account->requires_member && $line->memberId === null) {
                throw DomainRuleViolation::because('journal.errors.member_required', ['code' => $account->code, 'line' => $index + 1]);
            }

            if (! $account->requires_member && $line->memberId !== null) {
                throw DomainRuleViolation::because('journal.errors.member_not_allowed', ['code' => $account->code, 'line' => $index + 1]);
            }
        }
    }

    /**
     * Shared locks let postings run side by side while LockPeriod/CloseFiscalYear
     * (which take exclusive locks) wait for them, and vice versa.
     *
     * @return array{0: FiscalYear, 1: Period}
     */
    private function openPeriodFor(CarbonImmutable $date): array
    {
        $month = YearMonth::fromDate($date);
        $display = $date->toDateString();

        $fiscalYear = FiscalYear::query()
            ->where('start_year', FiscalCalendar::startYearFor($month))
            ->sharedLock()
            ->first();

        if ($fiscalYear === null) {
            throw DomainRuleViolation::because('journal.errors.no_fiscal_year', ['date' => $display]);
        }

        if (! $fiscalYear->isOpen()) {
            throw DomainRuleViolation::because('accounting.errors.fiscal_year_closed', ['code' => $fiscalYear->code]);
        }

        $period = Period::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('month', $month->toDateString())
            ->sharedLock()
            ->firstOrFail();

        if (! $period->isOpen()) {
            throw DomainRuleViolation::because('journal.errors.period_locked', ['month' => (string) $month]);
        }

        return [$fiscalYear, $period];
    }
}
