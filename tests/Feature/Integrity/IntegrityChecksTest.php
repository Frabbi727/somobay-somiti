<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Models\Due;
use App\Domain\Integrity\Actions\RunIntegrityChecks;
use App\Domain\Integrity\Checks\IntegrityCheck;
use App\Domain\Integrity\Enums\IntegrityRunStatus;
use App\Domain\Integrity\InvariantChecker;
use App\Domain\Integrity\Notifications\IntegrityCheckFailedMail;
use App\Domain\Notifications\Models\SmsMessage;
use App\Enums\Role;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
| SOMITI_SPEC.md P7.S1 / §6.7: each check passes on healthy books and catches its own kind of damage.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');

    $this->member = onboard(2, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    receivePayment($this->member, '3000');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Edits rows the way someone with direct database access could: with the guard triggers off.
 */
function tamper(string $sql, array $bindings = []): void
{
    DB::statement('SET session_replication_role = replica');
    DB::update($sql, $bindings);
    DB::statement('SET session_replication_role = DEFAULT');
}

/**
 * @return list<string> the keys of the checks that reported findings
 */
function failingChecks(): array
{
    $run = app(RunIntegrityChecks::class)();

    return $run->findings()->pluck('check')->unique()->values()->all();
}

it('passes every check on healthy books and stores the run', function (): void {
    Notification::fake();

    $run = app(RunIntegrityChecks::class)();

    expect($run->status)->toBe(IntegrityRunStatus::Passed)
        ->and($run->checks_run)->toBe(14)
        ->and($run->findings_count)->toBe(0)
        ->and($run->finished_at)->not->toBeNull();

    Notification::assertNothingSent();
});

it('detects a posted amount edited behind the application’s back (hash chain)', function (): void {
    tamper('UPDATE journal_lines SET debit_poisha = debit_poisha + 100 WHERE debit_poisha > 0 AND id = (SELECT MIN(id) FROM journal_lines WHERE debit_poisha > 0)');
    tamper('UPDATE journal_lines SET credit_poisha = credit_poisha + 100 WHERE credit_poisha > 0 AND id = (SELECT MIN(id) FROM journal_lines WHERE credit_poisha > 0)');

    expect(failingChecks())->toContain('journal_hash_chain');
});

it('detects an unbalanced voucher', function (): void {
    tamper('UPDATE journal_lines SET debit_poisha = debit_poisha + 1 WHERE id = (SELECT MIN(id) FROM journal_lines WHERE debit_poisha > 0)');

    expect(failingChecks())->toContain('balanced_entries');
});

it('detects a member whose 2111 balance differs from the advance ledger', function (): void {
    tamper('UPDATE advance_ledger_entries SET delta_poisha = delta_poisha - 100, balance_after_poisha = balance_after_poisha - 100 WHERE member_id = ?', [$this->member->id]);

    expect(failingChecks())->toContain('control_accounts');
});

it('detects a broken advance running balance', function (): void {
    tamper('UPDATE advance_ledger_entries SET balance_after_poisha = balance_after_poisha + 1 WHERE member_id = ?', [$this->member->id]);

    expect(failingChecks())->toBe(['advance_chain']);
});

it('detects a due whose paid amount no longer matches its allocations', function (): void {
    tamper('UPDATE dues SET paid_poisha = paid_poisha - 1 WHERE id = (SELECT MIN(due_id) FROM payment_allocations)');

    expect(failingChecks())->toContain('due_paid_amounts');
});

it('detects a payment whose allocations do not add up', function (): void {
    tamper('UPDATE payments SET amount_poisha = amount_poisha + 100');

    expect(failingChecks())->toBe(['payment_allocations']);
});

it('detects a due whose rate snapshot was rewritten', function (): void {
    $due = Due::query()->orderBy('id')->firstOrFail();
    tamper("UPDATE dues SET snapshot = jsonb_set(snapshot, '{share_unit_poisha}', '40000') WHERE id = ?", [$due->id]);

    expect(failingChecks())->toBe(['due_snapshots']);
});

it('detects a gap in voucher numbering', function (): void {
    tamper("UPDATE voucher_sequences SET last_number = last_number + 1 WHERE voucher_type = 'RV'");

    expect(failingChecks())->toContain('voucher_sequences');
});

it('reports a check that crashes instead of hiding it', function (): void {
    $checker = new InvariantChecker([new class implements IntegrityCheck
    {
        public function key(): string
        {
            return 'broken';
        }

        public function run(): array
        {
            throw new RuntimeException('boom');
        }
    }]);

    expect($checker->findings())->toBe(['Check failed to run: boom']);
});

it('alerts every super admin and accountant in the panel, by email and by SMS', function (): void {
    Notification::fake();
    Queue::fake();

    $admin = userWithRole(Role::SuperAdmin);
    $admin->update(['mobile' => '01711000000']);
    $accountant = userWithRole(Role::Accountant);
    $cashier = userWithRole(Role::Cashier);

    tamper('UPDATE payments SET amount_poisha = amount_poisha + 100');
    $run = app(RunIntegrityChecks::class)();

    expect($run->status)->toBe(IntegrityRunStatus::Failed);

    Notification::assertSentTo([$admin, $accountant], IntegrityCheckFailedMail::class);
    Notification::assertNotSentTo($cashier, IntegrityCheckFailedMail::class);
    Notification::assertSentTo([$admin, $accountant], DatabaseNotification::class);

    expect(SmsMessage::query()->where('to', '01711000000')->count())->toBe(1);
});

it('lets accountants, auditors and the super admin run checks on demand, but not cashiers', function (): void {
    expect(app(RunIntegrityChecks::class)(userWithRole(Role::Auditor))->triggered_by)->not->toBeNull();

    app(RunIntegrityChecks::class)(userWithRole(Role::Cashier));
})->throws(AuthorizationException::class);

it('runs from the command and is scheduled nightly at 02:00 Dhaka', function (): void {
    $this->artisan('somiti:integrity:check')->assertSuccessful();

    tamper('UPDATE payments SET amount_poisha = amount_poisha + 100');
    Notification::fake();
    $this->artisan('somiti:integrity:check')->assertFailed();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'somiti:integrity:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * *')
        ->and($event->timezone)->toBe('Asia/Dhaka');
});
