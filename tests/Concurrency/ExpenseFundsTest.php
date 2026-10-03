<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\ApproveExpense;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\RecordExpense;
use App\Domain\Accounting\Data\ExpenseData;
use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Models\Expense;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->artisan('migrate:fresh');
    $this->seed([RoleSeeder::class, ChartOfAccountsSeeder::class]);
});

afterEach(function (): void {
    DB::reconnect();
    $this->artisan('migrate:fresh');
});

it('never lets two approvals spend the same cash (P8.S1)', function (): void {
    $staff = fn (Role $role): User => tap(User::factory()->create(), fn (User $user) => $user->assignRole($role->value));

    $accountant = $staff(Role::Accountant);
    app(OpenFiscalYear::class)($accountant, 2026);
    $date = now('Asia/Dhaka')->toDateString();
    app(PostJournal::class)($accountant, simpleEntry('1101', '3101', '20000', $date));

    $cashier = $staff(Role::Cashier);
    $expenses = [];
    $checkers = [];

    foreach (range(1, 4) as $ignored) {
        $expenses[] = app(RecordExpense::class)($cashier, ExpenseData::fromForm([
            'account_id' => account('5199')->id,
            'paid_from' => 'cash',
            'amount' => Money::ofTaka('15000'),
            'spent_on' => $date,
            'description' => 'Competing withdrawal',
        ]))->id;
        $checkers[] = $staff(Role::President)->id;
    }

    $results = inParallel(4, function (int $index) use ($checkers, $expenses): string {
        app(ApproveExpense::class)(User::query()->findOrFail($checkers[$index]), Expense::query()->findOrFail($expenses[$index]));

        return 'approved';
    });

    expect(array_count_values($results)['approved'] ?? 0)->toBe(1)
        ->and(Expense::query()->where('status', ExpenseStatus::Approved)->count())->toBe(1)
        ->and(glBalance('1101'))->toBe(-500000);
});
