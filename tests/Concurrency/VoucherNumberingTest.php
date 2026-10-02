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

/**
 * Runs $work($index) in $workers forked processes at the same time and returns what each
 * returned (or "error: …"). Children report through files and kill themselves so the
 * PHPUnit shutdown handlers never run twice.
 *
 * @param  Closure(int): string  $work
 * @return array<int, string>
 */
function inParallel(int $workers, Closure $work): array
{
    $directory = sys_get_temp_dir().'/somiti-concurrency-'.bin2hex(random_bytes(4));
    mkdir($directory);

    DB::disconnect();
    $children = [];

    for ($index = 0; $index < $workers; $index++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Could not fork.');
        }

        if ($pid === 0) {
            try {
                DB::reconnect();
                $result = $work($index);
            } catch (Throwable $exception) {
                $result = 'error: '.$exception::class.': '.$exception->getMessage();
            }

            file_put_contents("{$directory}/{$index}", $result);
            posix_kill(posix_getpid(), SIGKILL);
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    DB::reconnect();

    $results = [];

    for ($index = 0; $index < $workers; $index++) {
        $results[$index] = (string) @file_get_contents("{$directory}/{$index}");
        @unlink("{$directory}/{$index}");
    }

    rmdir($directory);

    return $results;
}

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
