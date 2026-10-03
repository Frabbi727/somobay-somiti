<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Pages\Reports\AccountLedgerReport;
use App\Filament\Pages\Reports\TrialBalanceReport;
use Database\Seeders\DemoBooksSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(DemoBooksSeeder::class);
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    $this->actingAs(userWithRole(Role::Auditor));
});

it('shows a balanced trial balance and the reconciliation to an auditor', function (): void {
    Livewire::test(TrialBalanceReport::class)
        ->fillForm(['as_of' => '2026-09-30'])
        ->assertSee('Trial balance as of 30 Sep 2026')
        ->assertSee('৳ 9,842.40')
        ->assertSee(__('reports.trial_balance.balanced'))
        ->assertSee(__('reports.reconciliation.ok'))
        ->assertDontSee(__('reports.reconciliation.mismatch'));
});

it('downloads the trial balance as PDF and Excel', function (): void {
    Livewire::test(TrialBalanceReport::class)
        ->fillForm(['as_of' => '2026-09-30'])
        ->callAction('pdf')
        ->assertFileDownloaded('trial-balance-2026-09-30.pdf');

    Livewire::test(TrialBalanceReport::class)
        ->fillForm(['as_of' => '2026-09-30'])
        ->callAction('excel')
        ->assertFileDownloaded('trial-balance-2026-09-30.xlsx');
});

it('asks for an account before showing a ledger', function (): void {
    Livewire::test(AccountLedgerReport::class)
        ->assertSee(__('reports.ledger.pick_account'))
        ->assertActionDisabled('pdf');
});

it('shows and downloads an account ledger', function (): void {
    Livewire::test(AccountLedgerReport::class)
        ->fillForm(['account_id' => account('1101')->id, 'from' => '2026-07-01', 'until' => '2026-09-30'])
        ->assertSee('RV-2026-27-000001')
        ->assertSee('৳ 1,681.25 Dr')
        ->callAction('excel')
        ->assertFileDownloaded('ledger-1101-2026-07-01-2026-09-30.xlsx');
});

it('renders the report pages in Bangla over HTTP', function (): void {
    $this->get(route('filament.admin.pages.reports.trial-balance'))
        ->assertOk()
        ->assertSee('রেওয়ামিল');

    $this->get(route('filament.admin.pages.reports.ledger', ['account' => account('2101')->id]))
        ->assertOk()
        ->assertSee('হিসাব খতিয়ান');
});

it('keeps report pages away from members', function (): void {
    $this->actingAs(userWithRole(Role::Member))
        ->get(route('filament.admin.pages.reports.trial-balance'))
        ->assertForbidden();
});
