<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\Members\Actions\DeactivateMember;
use App\Domain\Reporting\CashMovements;
use App\Domain\Reporting\FinancialStatements;
use App\Domain\Reporting\MemberReports;
use App\Enums\Role;
use App\Filament\Pages\Reports\BalanceSheetPage;
use App\Filament\Pages\Reports\CashBookPage;
use App\Filament\Pages\Reports\CollectionSummaryPage;
use App\Filament\Pages\Reports\DefaultersPage;
use App\Filament\Pages\Reports\IncomeStatementPage;
use App\Filament\Pages\Reports\MemberStatementPage;
use App\Filament\Pages\Reports\ReceiptsPaymentsPage;
use App\Filament\Pages\Reports\ShareRegisterPage;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    $accountant = userWithRole(Role::Accountant);
    app(OpenFiscalYear::class)($accountant, 2026);
    approvedPlan('2026-07', '500'); // 500 + 10 service, 100 registration per share

    $this->alice = onboard(2, '2026-07', ['name_en' => 'Alice']);
    $this->bob = onboard(1, '2026-07', ['name_en' => 'Bob']);
    $carol = onboard(1, '2026-07', ['name_en' => 'Carol']);
    app(DeactivateMember::class)(userWithRole(Role::Secretary), $carol, 'Paused by committee');

    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($this->alice, '3000');                       // 200 + 1,020 + 1,780 advance
    receivePayment($this->bob, '300', 'bkash', 'BKREPORT01');    // partly paid

    app(PostJournal::class)($accountant, new JournalEntryData(VoucherType::Payment, CarbonImmutable::parse('2026-07-20'), 'Stationery', [
        JournalLineData::debit(account('5101'), Money::ofTaka('120.50')),
        JournalLineData::credit(account('1101'), Money::ofTaka('120.50')),
    ]));

    generateMonth('2026-08');
    travelTo('2026-09-20');
    $this->from = CarbonImmutable::parse('2026-07-01');
    $this->until = CarbonImmutable::parse('2026-09-20');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('balances the balance sheet and agrees with the income statement', function (): void {
    $statements = app(FinancialStatements::class);
    $balance = $statements->balanceSheet($this->until);
    $income = $statements->incomeStatement($this->from, $this->until);

    expect($balance['total_assets']->equals($balance['total_liabilities_equity']))->toBeTrue()
        ->and($balance['surplus']->equals($income['surplus']))->toBeTrue()
        ->and($income['total_expense']->poisha)->toBe(12050)
        // Recognised when paid: registration 300 (Alice 200 + Bob 100) and service 50
        // (Alice July + August, Bob July — service comes before registration in the payment order).
        ->and($income['total_income']->poisha)->toBe(35000)
        ->and($income['surplus']->poisha)->toBe(22950);
});

it('reconciles receipts and payments with cash at both ends', function (): void {
    $rp = app(CashMovements::class)->receiptsAndPayments($this->from, $this->until);

    expect($rp['opening']->poisha)->toBe(0)
        ->and($rp['total_receipts']->poisha)->toBe(330000)
        ->and($rp['total_payments']->poisha)->toBe(12050)
        ->and($rp['opening']->plus($rp['total_receipts'])->minus($rp['total_payments'])->equals($rp['closing']))->toBeTrue();
});

it('summarises collections so that charged = collected + outstanding', function (): void {
    $rows = app(MemberReports::class)->collectionSummary(YearMonth::of(2026, 7), YearMonth::of(2026, 9));

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row['paid']->plus($row['outstanding'])->equals($row['charged']))->toBeTrue();
    }

    // Carol was deactivated after joining but still owes her July registration fee.
    expect($rows[0]['members'])->toBe(3);
});

it('lists overdue members, largest first', function (): void {
    $defaulters = app(MemberReports::class)->defaulters($this->until);

    // Inactive members still owe what was charged before they were paused.
    expect(array_map(fn (array $row): string => $row['member']->name_en, $defaulters))->toBe(['Bob', 'Carol'])
        ->and($defaulters[0]['months'])->toBe(2)
        ->and((string) $defaulters[0]['oldest'])->toBe('2026-07')
        // Bob owed 100 + 510 (July) + 510 (August) and paid 300.
        ->and($defaulters[0]['outstanding']->poisha)->toBe(82000)
        ->and(app(MemberReports::class)->defaulters($this->until, 3))->toBe([]);
});

it('registers shares of active members only', function (): void {
    $register = app(MemberReports::class)->shareRegister(YearMonth::of(2026, 8));

    expect(array_map(fn (array $row): array => [$row['member']->name_en, $row['shares']], $register))
        ->toEqualCanonicalizing([['Alice', 2], ['Bob', 1], ['Carol', 1]]);
});

it('gives a member statement whose closing equals dues owed minus advance', function (): void {
    $statement = app(MemberReports::class)->statement($this->alice, $this->from, $this->until);
    $advance = app(AdvanceLedger::class)->balance($this->alice->id);

    // Alice was charged 200 + 1,020 + 1,020 and paid 3,000: 760 ahead, which is her advance.
    expect($statement['total_charges']->poisha)->toBe(224000)
        ->and($statement['total_paid']->poisha)->toBe(300000)
        ->and($statement['closing']->equals($advance->negated()))->toBeTrue();
});

it('renders every register in Bangla with PDF and Excel downloads', function (string $page, array $filters, string $file): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(userWithRole(Role::Auditor));

    $this->get($page::getUrl())->assertOk();

    Livewire::test($page)
        ->fillForm($filters)
        ->assertDontSee(__('reports.choose_filters'))
        ->callAction('pdf')
        ->assertFileDownloaded($file.'.pdf');

    Livewire::test($page)->fillForm($filters)->callAction('excel')->assertFileDownloaded($file.'.xlsx');
})->with([
    'income statement' => [IncomeStatementPage::class, ['from' => '2026-07-01', 'until' => '2026-09-20'], 'income-statement-2026-07-01-2026-09-20'],
    'balance sheet' => [BalanceSheetPage::class, ['as_of' => '2026-09-20'], 'balance-sheet-2026-09-20'],
    'receipts and payments' => [ReceiptsPaymentsPage::class, ['from' => '2026-07-01', 'until' => '2026-09-20'], 'receipts-payments-2026-07-01-2026-09-20'],
    'cash book' => [CashBookPage::class, ['account' => '1101', 'from' => '2026-07-01', 'until' => '2026-09-20'], 'cash-book-1101-2026-07-01-2026-09-20'],
    'collection summary' => [CollectionSummaryPage::class, ['from' => '2026-07', 'until' => '2026-09'], 'collection-summary-2026-07-2026-09'],
    'defaulters' => [DefaultersPage::class, ['as_of' => '2026-09-20', 'min_months' => 1], 'defaulters-2026-09-20'],
    'share register' => [ShareRegisterPage::class, ['month' => '2026-08'], 'share-register-2026-08'],
]);

it('renders a member statement for the chosen member', function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(userWithRole(Role::Auditor));
    app()->setLocale('en');

    Livewire::test(MemberStatementPage::class)
        ->assertSee(__('reports.choose_filters'))
        ->fillForm(['member' => $this->alice->id, 'from' => '2026-07-01', 'until' => '2026-09-20'])
        ->assertSee('-৳ 760.00')
        ->callAction('pdf')
        ->assertFileDownloaded('member-statement-'.$this->alice->id.'-2026-07-01-2026-09-20.pdf');
});
