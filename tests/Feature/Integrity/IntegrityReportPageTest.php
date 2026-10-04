<?php

declare(strict_types=1);

use App\Domain\Integrity\Actions\RunIntegrityChecks;
use App\Domain\Integrity\Enums\IntegrityRunStatus;
use App\Domain\Integrity\Jobs\RunIntegrityChecksJob;
use App\Domain\Integrity\Models\IntegrityFinding;
use App\Domain\Integrity\Models\IntegrityRun;
use App\Enums\Role;
use App\Filament\Pages\Reports\IntegrityReport;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->seed(ChartOfAccountsSeeder::class);
    Notification::fake();
    app()->setLocale('en');
});

/**
 * A failed run without touching the books: a raw voucher outside the numbering and hash chain.
 */
function failedRun(): void
{
    insertRawJournal(account('1101'), account('4101'));
    app(RunIntegrityChecks::class)();
}

it('shows the red banner to those who can act on it while the latest run has findings', function (): void {
    $cashier = userWithRole(Role::Cashier);
    $this->actingAs(userWithRole(Role::Accountant));

    app(RunIntegrityChecks::class)();
    $this->get(Dashboard::getUrl())->assertOk()->assertDontSee(__('integrity.banner.title', [], 'bn'));

    failedRun();
    $this->get(Dashboard::getUrl())->assertOk()->assertSee(__('integrity.banner.title', [], 'bn'));

    // The cash desk does not see it (nothing they can do about it).
    $this->actingAs($cashier)->get(Dashboard::getUrl())->assertOk()->assertDontSee(__('integrity.banner.title', [], 'bn'));
    $this->actingAs(User::query()->role('accountant')->firstOrFail());

    // Once the problem is fixed, the next passing run clears it.
    IntegrityRun::query()->create(['status' => IntegrityRunStatus::Passed, 'checks_run' => 8, 'started_at' => now(), 'finished_at' => now()]);
    $this->get(Dashboard::getUrl())->assertDontSee(__('integrity.banner.title', [], 'bn'));
});

it('lists the findings of the latest run', function (): void {
    $this->actingAs(userWithRole(Role::Auditor));
    failedRun();

    $findings = IntegrityFinding::query()->get();
    expect($findings)->not->toBeEmpty();

    $this->get(IntegrityReport::getUrl())->assertOk()->assertSee(__('integrity.status.failed', [], 'bn'));

    Livewire::test(IntegrityReport::class)
        ->assertCanSeeTableRecords($findings)
        ->assertSee(__('integrity.checks.voucher_sequences'));
});

it('queues a run on demand for accountants; cashiers cannot open the report', function (): void {
    Queue::fake();

    $this->actingAs(userWithRole(Role::Accountant));
    Livewire::test(IntegrityReport::class)->callAction('run')->assertNotified(__('integrity.queued'));
    Queue::assertPushed(RunIntegrityChecksJob::class);

    $this->actingAs(userWithRole(Role::Cashier));
    $this->get(IntegrityReport::getUrl())->assertForbidden();

    $this->actingAs(userWithRole(Role::President));
    Livewire::test(IntegrityReport::class)->assertActionHidden('run');
});
