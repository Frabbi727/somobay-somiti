<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\CloseFiscalYear;
use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Enums\FiscalYearStatus;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\Reconciliation;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Actions\ApproveYearEnd;
use App\Domain\YearEnd\Actions\PrepareYearEnd;
use App\Domain\YearEnd\Data\AppropriationRates;
use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\DividendLine;
use App\Domain\YearEnd\Models\YearEnd;
use App\Enums\Role;
use App\Filament\Pages\YearEnd\YearEndWizard;
use App\Filament\Resources\YearEnds\Pages\ViewYearEnd;
use App\Filament\Resources\YearEnds\RelationManagers\DividendLinesRelationManager;
use App\Filament\Support\Display;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Phase 11 (P11.S2): prepare → president + accountant approve (T3) → closing & appropriation JVs,
| dividend lines, year closed, next year opened (W8).
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    $this->president = userWithRole(Role::President);
    $this->fy = app(OpenFiscalYear::class)($this->accountant, 2026);
    approvedPlan('2026-07', '500');

    $this->rahim = onboard(2, '2026-07');
    $this->karim = onboard(1, '2026-07');

    // ৳12,000 service-charge income and ৳2,000 expenses → net profit ৳10,000.
    app(PostJournal::class)($this->accountant, simpleEntry('1101', '4111', '12000', '2026-12-15'));
    app(PostJournal::class)($this->accountant, simpleEntry('5101', '1101', '2000', '2027-01-15'));

    travelTo('2027-07-05');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function lockAllButLast(FiscalYear $fy): void
{
    $accountant = User::query()->role('accountant')->firstOrFail();

    foreach ($fy->periods()->orderBy('sequence')->get()->slice(0, -1) as $period) {
        app(LockPeriod::class)($accountant, $period);
    }
}

function prepare(FiscalYear $fy): YearEnd
{
    return app(PrepareYearEnd::class)(User::query()->role('accountant')->firstOrFail(), $fy, AppropriationRates::defaults());
}

function workflowRule(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

it('requires every month but the last to be locked first', function (): void {
    expect(workflowRule(fn () => prepare($this->fy)))->toBe('year_end.errors.periods_open');
});

it('prepares a draft, then posts it on the president’s and accountant’s approval', function (): void {
    lockAllButLast($this->fy);
    $draft = prepare($this->fy);

    expect($draft->status)->toBe(YearEndStatus::Draft)
        ->and($draft->net_profit_poisha->poisha)->toBe(1000000)
        ->and($draft->appropriation)->toEqual(['reserve' => 150000, 'development_fund' => 30000, 'bad_debt_fund' => 0, 'other_funds' => 0])
        ->and($draft->dividend_pool_poisha->poisha)->toBe(820000)
        ->and($draft->total_share_months)->toBe(36);

    app(ApproveYearEnd::class)($this->president, $draft);
    expect($draft->fresh()?->status)->toBe(YearEndStatus::Draft);

    $posted = app(ApproveYearEnd::class)(userWithRole(Role::Accountant), $draft);

    expect($posted->status)->toBe(YearEndStatus::Posted)
        ->and(glBalance('4111'))->toBe(0)
        ->and(glBalance('5101'))->toBe(0)
        ->and(glBalance('3201'))->toBe(150000)
        ->and(glBalance('2211'))->toBe(30000)
        ->and(glBalance('2201'))->toBe(820000)
        ->and(glBalance('3901'))->toBe(0)
        ->and(DividendLine::query()->orderBy('member_id')->pluck('amount_poisha', 'member_id')->map->poisha->all())
        ->toBe([$this->rahim->id => 546667, $this->karim->id => 273333]) // 24 : 12 share-months
        ->and($this->fy->fresh()?->status)->toBe(FiscalYearStatus::Closed)
        ->and(FiscalYear::query()->where('start_year', 2027)->exists())->toBeTrue()
        ->and(JournalEntry::query()->find($posted->appropriation_journal_entry_id)?->entry_date->toDateString())->toBe('2027-06-30');

    $dividends = collect(app(Reconciliation::class)->controlVsSubledger(CarbonImmutable::parse('2027-07-05')))
        ->firstWhere(fn ($check): bool => $check->account->code === '2201');

    expect($dividends?->isReconciled())->toBeTrue();
    assertBooksTieOut();
});

it('refuses an approval when the books changed after the draft', function (): void {
    lockAllButLast($this->fy);
    $draft = prepare($this->fy);

    app(PostJournal::class)($this->accountant, simpleEntry('5101', '1101', '10', '2027-06-20'));

    expect(workflowRule(fn () => app(ApproveYearEnd::class)($this->president, $draft)))->toBe('year_end.errors.stale');

    $again = prepare($this->fy);
    expect($again->net_profit_poisha->poisha)->toBe(999000);
});

it('needs two different approvers: the president and an accountant', function (): void {
    lockAllButLast($this->fy);
    $draft = prepare($this->fy);

    app(ApproveYearEnd::class)($this->president, $draft);

    expect(fn () => app(ApproveYearEnd::class)($this->president, $draft))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ApproveYearEnd::class)(userWithRole(Role::Secretary), $draft))->toThrow(AuthorizationException::class);
});

it('needs a passed year-end resolution where configured', function (): void {
    config(['somiti.require_resolution_for' => ['year_end']]);
    lockAllButLast($this->fy);
    $draft = prepare($this->fy);

    expect(workflowRule(fn () => app(ApproveYearEnd::class)($this->president, $draft)))->toBe('governance.errors.resolution_required');
});

it('closes a loss year into the deficit without any dividend', function (): void {
    app(PostJournal::class)($this->accountant, simpleEntry('5103', '1101', '15000', '2027-02-10'));
    lockAllButLast($this->fy);

    $draft = prepare($this->fy);
    app(ApproveYearEnd::class)($this->president, $draft);
    $posted = app(ApproveYearEnd::class)(userWithRole(Role::Accountant), $draft);

    expect($posted->net_profit_poisha->poisha)->toBe(-500000)
        ->and($posted->appropriation_journal_entry_id)->toBeNull()
        ->and(glBalance('3901'))->toBe(-500000)
        ->and(DividendLine::query()->count())->toBe(0);

    assertBooksTieOut();
});

it('keeps posted year-ends and dividend amounts fixed', function (): void {
    lockAllButLast($this->fy);
    $draft = prepare($this->fy);
    app(ApproveYearEnd::class)($this->president, $draft);
    app(ApproveYearEnd::class)(userWithRole(Role::Accountant), $draft);

    // Each attempt in its own savepoint, so the test's transaction survives the refusal.
    expect(fn () => DB::transaction(fn () => DB::table('year_ends')->where('id', $draft->id)->update(['dividend_pool_poisha' => 1])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('dividend_lines')->where('member_id', $this->rahim->id)->update(['amount_poisha' => 1])))->toThrow(QueryException::class)
        ->and(workflowRule(fn () => prepare($this->fy)))->toBe('accounting.errors.fiscal_year_closed');
});

it('runs the closing wizard and the two approvals from the screens', function (): void {
    Filament\Facades\Filament::setCurrentPanel('admin');
    lockAllButLast($this->fy);
    $this->actingAs($this->accountant);

    $this->get(YearEndWizard::getUrl())->assertOk();

    Livewire\Livewire::test(YearEndWizard::class)
        ->assertSee(Display::money(Money::ofTaka('8200')))
        ->callAction('prepare')
        ->assertHasNoActionErrors();

    $draft = YearEnd::query()->sole();

    $this->actingAs($this->president);
    Livewire\Livewire::test(ViewYearEnd::class, ['record' => $draft->getRouteKey()])
        ->callAction('approve', data: ['confirm_text' => 'wrong'])
        ->assertHasActionErrors(['confirm_text']);

    Livewire\Livewire::test(ViewYearEnd::class, ['record' => $draft->getRouteKey()])
        ->callAction('approve', data: ['confirm_text' => $this->fy->code])
        ->assertHasNoActionErrors();

    $this->actingAs(userWithRole(Role::Accountant));
    Livewire\Livewire::test(ViewYearEnd::class, ['record' => $draft->getRouteKey()])
        ->callAction('approve', data: ['confirm_text' => $this->fy->code])
        ->assertHasNoActionErrors();

    expect($draft->fresh()?->status)->toBe(YearEndStatus::Posted);

    Livewire\Livewire::test(DividendLinesRelationManager::class, [
        'ownerRecord' => $draft->fresh(),
        'pageClass' => ViewYearEnd::class,
    ])->assertCanSeeTableRecords(DividendLine::query()->get());
});

it('does not let a year with income or expenses be closed directly', function (): void {
    lockAllButLast($this->fy);

    expect(workflowRule(fn () => app(CloseFiscalYear::class)($this->president, $this->fy)))
        ->toBe('accounting.errors.year_end_required');
});
