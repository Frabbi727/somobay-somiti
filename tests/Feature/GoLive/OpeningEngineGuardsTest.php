<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Contributions\Actions\ApplyLateFees;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\Integrity\Checks\InvestmentPostings;
use App\Domain\Investments\Enums\InvestmentEntryKind;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentType;
use App\Domain\Investments\Models\Investment;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Services\ShareChanger;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->admin = userWithRole(Role::SuperAdmin);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function openingGuardMember(User $admin): Member
{
    return Member::query()->create([...memberData()->toAttributes(), 'member_no' => 'M-0001', 'status' => MemberStatus::Active, 'created_by' => $admin->id]);
}

it('opens a share lot in a month with no rate plan and charges no registration fee', function (): void {
    $member = openingGuardMember($this->admin);

    $lot = app(ShareChanger::class)->openingLot($this->admin, $member, 3, YearMonth::parse('2019-01'));

    expect($lot->shares)->toBe(3)
        ->and($member->sharesIn(YearMonth::parse('2026-07')))->toBe(3)
        ->and(Due::query()->count())->toBe(0);
});

it('refuses to generate dues for a month before go-live', function (): void {
    CarbonImmutable::setTestNow('2026-07-05');
    approvedPlan('2026-06', '500');
    OpeningImport::query()->create(['status' => OpeningImportStatus::Posted, 'go_live_month' => YearMonth::parse('2026-07'), 'file_path' => 'p', 'file_name' => 'p', 'file_sha256' => str_repeat('c', 64), 'payload' => [], 'errors' => [], 'summary' => [], 'uploaded_by' => $this->admin->id]);

    expect(fn () => app(GenerateMonthlyDues::class)(YearMonth::parse('2026-06')))
        ->toThrow(DomainRuleViolation::class, __('dues.errors.before_go_live', ['month' => '2026-06', 'go_live' => '2026-07']));
});

it('never charges a late fee on opening arrears', function (): void {
    CarbonImmutable::setTestNow('2026-09-20');
    $plan = approvedPlan('2026-07', '500');
    $member = openingGuardMember($this->admin);
    $lot = app(ShareChanger::class)->openingLot($this->admin, $member, 1, YearMonth::parse('2020-01'));
    Due::query()->create([
        'member_id' => $member->id, 'month' => YearMonth::parse('2026-06'), 'type' => DueType::Deposit, 'share_lot_id' => $lot->id,
        'adjustment_seq' => 0, 'rate_plan_id' => $plan->id, 'snapshot' => $plan->snapshot(), 'amount_poisha' => Money::ofTaka('1500'),
        'paid_poisha' => Money::zero(), 'due_date' => '2026-06-30', 'status' => DueStatus::Open, 'opening' => true,
    ]);

    expect(app(ApplyLateFees::class)()['count'])->toBe(0);
});

it('keeps the investment check clean for an investment opened on its own voucher', function (): void {
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    CarbonImmutable::setTestNow('2026-07-01');
    $entry = app(PostJournal::class)($this->admin, new JournalEntryData(VoucherType::Journal, CarbonImmutable::parse('2026-07-01'), 'Opening balance: investment', [
        JournalLineData::debit(account('1301'), Money::ofTaka('100000')),
        JournalLineData::credit(account('3901'), Money::ofTaka('100000')),
    ]));

    $investment = Investment::query()->create([
        'investment_no' => 'INV-0001', 'type' => InvestmentType::FixedDeposit, 'account_id' => account('1301')->id,
        'institution' => 'Bank', 'principal_poisha' => Money::ofTaka('100000'), 'funded_from' => 'bank', 'invested_on' => '2025-01-01',
        'status' => InvestmentStatus::Active, 'idempotency_key' => (string) Str::uuid(), 'recorded_by' => $this->admin->id, 'journal_entry_id' => $entry->id,
    ]);
    $investment->ledger()->create(['kind' => InvestmentEntryKind::Opening, 'delta_poisha' => $investment->principal_poisha, 'journal_entry_id' => $entry->id, 'created_by' => $this->admin->id]);

    expect(app(InvestmentPostings::class)->run())->toBe([]);
});
