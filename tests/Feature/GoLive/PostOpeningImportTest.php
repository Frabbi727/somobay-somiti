<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Actions\ApplyAdvance;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Domain\GoLive\Actions\PostOpeningImport;
use App\Domain\GoLive\Actions\UploadOpeningImport;
use App\Domain\GoLive\Enums\OpeningImportStatus;
use App\Domain\GoLive\Models\OpeningImport;
use App\Domain\Investments\Actions\ReverseInvestment;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Events\MemberJoined;
use App\Domain\Members\Models\Member;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-01 10:00');
    $this->seed(ChartOfAccountsSeeder::class);
    Storage::fake('local');
    $this->admin = userWithRole(Role::SuperAdmin);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function goLiveDraft(array $rows): OpeningImport
{
    return app(UploadOpeningImport::class)(test()->admin, storeOpeningFile($rows), 'sheet.xlsx', YearMonth::parse('2026-07'));
}

function goLiveSheet(): array
{
    return [
        'members' => [
            memberRow(['member_no' => 'M-0042', 'shares' => '2', 'joined_on' => '2019-03-15', 'savings' => '30000', 'advance' => '2500']),
            memberRow(['member_no' => 'M-0007', 'shares' => '1', 'joined_on' => '2021-01-01', 'savings' => '12000', 'arrears_deposit' => '1500', 'arrears_fees' => '60']),
        ],
        'society' => [['account_code' => '1101', 'amount' => '4000'], ['account_code' => '1111', 'amount' => '20000'], ['account_code' => '3201', 'amount' => '3000']],
        'investments' => [['type' => 'fixed_deposit', 'name' => 'Sonali FDR', 'institution' => 'Sonali Bank', 'instrument_no' => 'FDR-9', 'principal' => '25000', 'invested_on' => '2025-01-10', 'matures_on' => '2027-01-10', 'expected_rate_percent' => '8', 'funded_from' => 'bank']],
    ];
}

it('posts members, shares, arrears, advance, investments and balanced opening vouchers', function (): void {
    Event::fake([MemberJoined::class]);

    $import = app(PostOpeningImport::class)($this->admin, goLiveDraft(goLiveSheet()));
    $rahim = Member::query()->where('member_no', 'M-0042')->sole();
    $karim = Member::query()->where('member_no', 'M-0007')->sole();
    $entry = JournalEntry::query()->with('lines.account')->findOrFail($import->journal_entry_id);
    $investment = Investment::query()->sole();

    expect($import->status)->toBe(OpeningImportStatus::Posted)
        ->and($import->posted_by)->toBe($this->admin->id)
        ->and($rahim->sharesIn(YearMonth::parse('2019-03')))->toBe(2)
        ->and($rahim->sharesIn(YearMonth::parse('2019-02')))->toBe(0)
        ->and(Due::query()->where('type', DueType::Registration)->count())->toBe(0)
        ->and(app(AdvanceLedger::class)->balance($rahim->id)->poisha)->toBe(250000)
        ->and(Due::query()->where('member_id', $karim->id)->where('opening', true)->get()->map(fn (Due $due): int => $due->amount_poisha->poisha)->sort()->values()->all())->toBe([6000, 150000])
        ->and(Due::query()->where('opening', true)->first()?->month->equals(YearMonth::parse('2026-06')))->toBeTrue()
        ->and($investment->status)->toBe(InvestmentStatus::Active)
        ->and($investment->name)->toBe('Sonali FDR')
        ->and($investment->journal_entry_id)->not->toBe($entry->id)
        ->and($entry->entry_date->toDateString())->toBe('2026-07-01')
        ->and($entry->lines->sum(fn ($l) => $l->debit_poisha->poisha))->toBe($entry->lines->sum(fn ($l) => $l->credit_poisha->poisha))
        ->and(glBalance('1301'))->toBe(-2500000)
        // held 4,000 + 20,000 + 25,000 = 49,000; owed 30,000 + 2,500 + 12,000 + 3,000 = 47,500 → 3901 credit 1,500 net
        ->and(glBalance('3901'))->toBe(150000)
        ->and(glBalance('2101'))->toBe(4200000)
        ->and(glBalance('2111'))->toBe(250000);

    Event::assertNotDispatched(MemberJoined::class);
    assertBooksTieOut();

    // New members continue after the highest imported number.
    $next = app(CreateMember::class)(userWithRole(Role::Secretary), memberData(['mobile' => '01999999999']), 1, YearMonth::parse('2026-07'));
    expect($next->member_no)->toBe('M-0043');
});

it('hands over to the normal cycle: go-live dues are generated and settled from the advance', function (): void {
    app(PostOpeningImport::class)($this->admin, goLiveDraft(goLiveSheet()));
    $rahim = Member::query()->where('member_no', 'M-0042')->sole();

    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-07'));
    app(ApplyAdvance::class)();

    $july = Due::query()->where('member_id', $rahim->id)->where('month', '2026-07-01')->get();

    expect($july)->not->toBeEmpty()
        ->and($july->sum(fn ($due) => $due->outstanding_poisha->poisha))->toBe(0)
        ->and(app(AdvanceLedger::class)->balance($rahim->id)->poisha)->toBe(250000 - $july->sum(fn ($due) => $due->amount_poisha->poisha));

    assertBooksTieOut();
});

it('keeps the integrity checks clean with two opening investments on the same account', function (): void {
    $sheet = goLiveSheet();
    $sheet['investments'][] = [...$sheet['investments'][0], 'name' => 'Janata FDR', 'institution' => 'Janata Bank', 'instrument_no' => 'FDR-10', 'principal' => '10000'];

    app(PostOpeningImport::class)($this->admin, goLiveDraft($sheet));

    expect(Investment::query()->count())->toBe(2)
        ->and(Investment::query()->distinct()->count('journal_entry_id'))->toBe(2)
        ->and(glBalance('1301'))->toBe(-3500000);

    assertBooksTieOut();
});

it('lets an opening investment be reversed on its own, leaving the rest of the opening untouched', function (): void {
    app(PostOpeningImport::class)($this->admin, goLiveDraft(goLiveSheet()));

    app(ReverseInvestment::class)(userWithRole(Role::President), Investment::query()->sole(), 'Was sold before go-live');

    expect(glBalance('1301'))->toBe(0)
        ->and(glBalance('2101'))->toBe(4200000)
        ->and(glBalance('1111'))->toBe(-2000000);

    assertBooksTieOut();
});

it('refuses a draft with errors, a changed file, and a second post', function (): void {
    $bad = goLiveDraft(['members' => [memberRow(['member_no' => 'x'])]]);
    expect(fn () => app(PostOpeningImport::class)($this->admin, $bad))->toThrow(DomainRuleViolation::class, __('golive.errors.not_clean'));

    $draft = goLiveDraft(goLiveSheet());
    Storage::disk('local')->put($draft->file_path, 'tampered');
    expect(fn () => app(PostOpeningImport::class)($this->admin, $draft))->toThrow(DomainRuleViolation::class, __('golive.errors.file_changed'));

    $draft = goLiveDraft(goLiveSheet());
    app(PostOpeningImport::class)($this->admin, $draft);
    $vouchers = JournalEntry::query()->count();

    expect(fn () => app(PostOpeningImport::class)($this->admin, $draft))->toThrow(DomainRuleViolation::class, __('golive.errors.not_draft'))
        ->and(JournalEntry::query()->count())->toBe($vouchers);
});

it('refuses when members already exist, without a rate plan for the go-live month, and for users without the permission', function (): void {
    $draft = goLiveDraft(goLiveSheet());

    expect(fn () => app(PostOpeningImport::class)(userWithRole(Role::Accountant), $draft))->toThrow(AuthorizationException::class);

    onboard(1, '2026-07');

    expect(fn () => app(PostOpeningImport::class)($this->admin, $draft))->toThrow(DomainRuleViolation::class, __('golive.errors.not_fresh'))
        ->and(Member::query()->count())->toBe(1);
});

it('needs an approved rate plan covering the go-live month', function (): void {
    $draft = app(UploadOpeningImport::class)($this->admin, storeOpeningFile(goLiveSheet()), 'sheet.xlsx', YearMonth::parse('2026-05'));

    expect(fn () => app(PostOpeningImport::class)($this->admin, $draft))->toThrow(DomainRuleViolation::class, __('members.errors.no_rate_plan', ['month' => '2026-05']))
        ->and(Member::query()->count())->toBe(0);
});
