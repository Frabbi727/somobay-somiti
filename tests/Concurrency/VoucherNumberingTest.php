<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Models\JournalEntry;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
| These tests fork real OS processes, each with its own database connection, so they commit
| for real. They rebuild the schema before and after so other suites start clean.
*/

beforeEach(function (): void {
    $this->artisan('migrate:fresh');
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);

    $this->accountant = User::factory()->create();
    $this->accountant->assignRole(Role::Accountant->value);

    app(OpenFiscalYear::class)($this->accountant, 2026);
});

afterEach(function (): void {
    DB::reconnect();
    $this->artisan('migrate:fresh');
});

it('gives 50 concurrent postings gap-free unique numbers even when others roll back', function (): void {
    $accountantId = $this->accountant->id;

    // 60 workers: every sixth one takes a number and then rolls back on purpose.
    $results = inParallel(60, function (int $index) use ($accountantId): string {
        $actor = User::query()->findOrFail($accountantId);
        $post = fn (): JournalEntry => app(PostJournal::class)($actor, simpleEntry('1101', '4111', (string) ($index + 1)));

        if ($index % 6 === 5) {
            try {
                DB::transaction(function () use ($post): void {
                    $post();

                    throw new RuntimeException('deliberate rollback');
                });
            } catch (RuntimeException) {
                return 'rolled back';
            }
        }

        return $post()->voucher_no;
    });

    $numbers = array_values(array_filter($results, fn (string $result): bool => str_starts_with($result, 'JV-')));
    sort($numbers);

    $expected = array_map(fn (int $n): string => sprintf('JV-2026-27-%06d', $n), range(1, 50));

    expect(array_filter($results, fn (string $result): bool => str_starts_with($result, 'error')))->toBe([])
        ->and(count(array_keys($results, 'rolled back', true)))->toBe(10)
        ->and($numbers)->toBe($expected)
        ->and(JournalEntry::query()->orderBy('voucher_no')->pluck('voucher_no')->all())->toBe($expected)
        ->and((int) DB::table('voucher_sequences')->value('last_number'))->toBe(50);
});

it('lets only one of several simultaneous reversals of the same voucher succeed', function (): void {
    $entry = app(PostJournal::class)($this->accountant, simpleEntry('1101', '4111', '100'));
    $accountantId = $this->accountant->id;

    $results = inParallel(8, function () use ($accountantId, $entry): string {
        $actor = User::query()->findOrFail($accountantId);

        return app(ReverseJournal::class)($actor, JournalEntry::query()->findOrFail($entry->id), 'Duplicate receipt')->voucher_no;
    });

    $succeeded = array_values(array_filter($results, fn (string $result): bool => str_starts_with($result, 'JV-')));

    expect($succeeded)->toHaveCount(1)
        ->and(JournalEntry::query()->where('reverses_id', $entry->id)->count())->toBe(1)
        ->and(array_filter($results, fn (string $result): bool => ! str_starts_with($result, 'JV-') && ! str_contains($result, 'DomainRuleViolation') && ! str_contains($result, 'AuthorizationException')))->toBe([]);
});
