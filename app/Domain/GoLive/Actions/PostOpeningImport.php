<?php

declare(strict_types=1);

namespace App\Domain\GoLive\Actions;

use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Contributions\Enums\AdvanceEntryKind;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\GoLive\Data\OpeningInvestmentRow;
use App\Domain\GoLive\Data\OpeningMemberRow;
use App\Domain\GoLive\Data\OpeningWorkbook;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\GoLive\Services\OpeningBalanceSheet;
use App\Domain\GoLive\Services\OpeningImportValidator;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Members\Services\ShareChanger;
use App\Domain\Settings\Exceptions\NoRatePlanForMonth;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Settings\Services\RateResolver;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Posts the go-live import once, in one transaction: members (with their own numbers), opening
 * share lots (no registration fee), portal accounts, opening arrears dues, the main opening
 * voucher and the advance entries tied to it, and each investment on its own opening voucher.
 * No welcome message is sent. After this the import is closed for good.
 */
final class PostOpeningImport
{
    public function __construct(
        private readonly OpeningImportValidator $validator,
        private readonly OpeningBalanceSheet $sheet,
        private readonly ShareChanger $shares,
        private readonly PortalAccounts $portal,
        private readonly AdvanceLedger $advances,
        private readonly RateResolver $rates,
        private readonly Accounts $accounts,
        private readonly PostJournal $post,
        private readonly CauserResolver $causer,
    ) {}

    public function __invoke(User $actor, OpeningImport $import): OpeningImport
    {
        Gate::forUser($actor)->authorize('post', $import);

        return $this->causer->withCauser($actor, fn (): OpeningImport => DB::transaction(function () use ($actor, $import): OpeningImport {
            $locked = OpeningImport::query()->whereKey($import->getKey())->lockForUpdate()->firstOrFail();
            $goLive = $locked->go_live_month;

            if ($locked->status !== OpeningImportStatus::Draft) {
                throw DomainRuleViolation::because('golive.errors.not_draft');
            }

            if (hash('sha256', (string) Storage::disk('local')->get($locked->file_path)) !== $locked->file_sha256) {
                throw DomainRuleViolation::because('golive.errors.file_changed');
            }

            $opening = $this->validator->validate(OpeningWorkbook::fromArray($locked->payload), $goLive);

            if (! $opening->isClean()) {
                throw DomainRuleViolation::because('golive.errors.not_clean');
            }

            $this->assertFresh();

            try {
                $plan = $this->rates->for($goLive);
            } catch (NoRatePlanForMonth) {
                throw DomainRuleViolation::because('members.errors.no_rate_plan', ['month' => (string) $goLive]);
            }

            $memberIds = [];
            $highest = 0;

            foreach ($opening->members as $row) {
                $member = $this->createMember($actor, $row, $plan, $goLive->previous());
                $memberIds[$row->memberNo] = $member->id;
                $highest = max($highest, (int) substr($row->memberNo, 2));
            }

            // The members table was empty (assertFresh), so numbering continues right after the
            // highest imported number even if wiped test data had advanced the sequence.
            DB::statement("SELECT setval('member_no_seq', ?, ?)", [max($highest, 1), $highest > 0]);

            $narration = __('golive.narration', ['month' => (string) $goLive], 'en');
            $lines = $this->sheet->journalLines($opening, $memberIds);
            $entry = $lines === [] ? null : ($this->post)($actor, new JournalEntryData(
                type: VoucherType::Journal,
                entryDate: $goLive->firstDay(),
                narration: is_string($narration) ? $narration : 'Opening balances',
                lines: $lines,
                source: $locked,
            ));

            foreach ($opening->members as $row) {
                if ($row->advance->isPositive()) {
                    $this->advances->append($memberIds[$row->memberNo], AdvanceEntryKind::Opening, $row->advance, [
                        'journal_entry_id' => $entry?->id,
                        'created_by' => $actor->id,
                    ]);
                }
            }

            foreach ($opening->investments as $row) {
                $this->createInvestment($actor, $row, $goLive);
            }

            $locked->update([
                'status' => OpeningImportStatus::Posted,
                'journal_entry_id' => $entry?->id,
                'posted_by' => $actor->id,
                'posted_at' => CarbonImmutable::now(),
            ]);

            activity('golive')
                ->performedOn($locked)
                ->event('opening_import_posted')
                ->withProperties(['go_live' => (string) $goLive, 'members' => count($opening->members), 'investments' => count($opening->investments), 'voucher' => $entry?->voucher_no])
                ->log('opening import posted');

            return $locked;
        }, attempts: 3));
    }

    /**
     * A member with the sheet's number, an opening share lot from the joining month and any
     * opening arrears, dated the month before go-live so they are settled before go-live dues.
     */
    private function createMember(User $actor, OpeningMemberRow $row, RatePlan $plan, YearMonth $arrearsMonth): Member
    {
        $member = Member::query()->create([
            ...$row->member->toAttributes(),
            'member_no' => $row->memberNo,
            'status' => MemberStatus::Active,
            'created_by' => $actor->id,
        ]);

        $lot = $this->shares->openingLot($actor, $member, $row->shares, YearMonth::fromDate($row->member->joinedOn));
        $this->portal->forMember($member);

        foreach ([[DueType::Deposit, $row->arrearsDeposit], [DueType::LateFee, $row->arrearsFees]] as [$type, $amount]) {
            if (! $amount->isPositive()) {
                continue;
            }

            Due::query()->create([
                'member_id' => $member->id,
                'month' => $arrearsMonth,
                'type' => $type,
                'share_lot_id' => $lot->id,
                'adjustment_seq' => 0,
                'rate_plan_id' => $plan->id,
                'snapshot' => $plan->snapshot(),
                'amount_poisha' => $amount,
                'paid_poisha' => Money::zero(),
                'due_date' => $arrearsMonth->lastDay()->toDateString(),
                'status' => DueStatus::Open,
                'opening' => true,
                'note' => 'Opening arrears',
            ]);
        }

        return $member;
    }

    /**
     * An active investment on its own opening voucher (Dr 13xx / Cr 3901), so it can later be
     * closed, sold, written down or reversed exactly like one recorded in the app.
     */
    private function createInvestment(User $actor, OpeningInvestmentRow $row, YearMonth $goLive): Investment
    {
        $number = sprintf('INV-%04d', (int) DB::scalar("SELECT nextval('investment_no_seq')"));
        $name = OpeningBalanceSheet::investmentMemo($row);
        $narration = __('golive.investment_narration', ['number' => $number, 'name' => $name], 'en');

        $entry = ($this->post)($actor, new JournalEntryData(
            type: VoucherType::Journal,
            entryDate: $goLive->firstDay(),
            narration: is_string($narration) ? $narration : 'Opening balance '.$number,
            lines: $this->sheet->investmentLines($row),
        ));

        $investment = Investment::query()->create([
            'investment_no' => $number,
            'type' => $row->type,
            'account_id' => $this->accounts->byCode($row->type->accountCode())->id,
            'name' => $name,
            'institution' => $row->institution,
            'instrument_no' => $row->instrumentNo,
            'principal_poisha' => $row->principal,
            'funded_from' => $row->fundedFrom,
            'invested_on' => $row->investedOn->toDateString(),
            'matures_on' => $row->maturesOn?->toDateString(),
            'expected_rate_bps' => $row->expectedRate?->value,
            'notes' => 'Opening balance',
            'status' => InvestmentStatus::Active,
            'idempotency_key' => (string) Str::uuid(),
            'recorded_by' => $actor->id,
            'journal_entry_id' => $entry->id,
        ]);

        $investment->ledger()->create([
            'kind' => InvestmentEntryKind::Opening,
            'delta_poisha' => $row->principal,
            'journal_entry_id' => $entry->id,
            'created_by' => $actor->id,
        ]);

        return $investment;
    }

    /**
     * The import is a fresh start: nothing may exist that it would sit beside.
     */
    private function assertFresh(): void
    {
        if (Member::withTrashed()->exists() || JournalEntry::query()->exists() || Payment::query()->exists() || Due::query()->exists()) {
            throw DomainRuleViolation::because('golive.errors.not_fresh');
        }
    }
}
