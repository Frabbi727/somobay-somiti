<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\RecordExpense;
use App\Domain\Accounting\Actions\RecordFundTransfer;
use App\Domain\Accounting\Data\ExpenseData;
use App\Domain\Accounting\Data\FundTransferData;
use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\RecordInvestment;
use App\Domain\Investments\Actions\RecordInvestmentTopUp;
use App\Domain\Investments\Data\InvestmentData;
use App\Domain\Investments\Data\InvestmentTopUpData;
use App\Domain\Investments\Models\Investment;
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

it('records once when the same submission arrives several times at once', function (Role $role, Closure $record): void {
    $user = tap(User::factory()->create(), fn (User $user) => $user->assignRole($role->value));
    app(OpenFiscalYear::class)(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::Accountant->value)), 2026);
    $key = '6f1d2c3b-4a5e-4f70-8a9b-0c1d2e3f4a5b';

    $results = inParallel(4, fn (): string => 'record '.$record(User::query()->findOrFail($user->id), $key));

    expect(array_unique($results))->toHaveCount(1)
        ->and($results[0])->toStartWith('record ');
})->with([
    'expense' => [Role::Cashier, fn (User $actor, string $key): int => app(RecordExpense::class)($actor, ExpenseData::fromForm([
        'account_id' => account('5199')->id, 'paid_from' => 'cash', 'amount' => Money::ofTaka('500'),
        'spent_on' => now('Asia/Dhaka')->toDateString(), 'description' => 'Office tea', 'idempotency_key' => $key,
    ]))->id],
    'fund transfer' => [Role::Accountant, fn (User $actor, string $key): int => app(RecordFundTransfer::class)($actor, FundTransferData::fromForm([
        'from_method' => 'cash', 'to_method' => 'bank', 'amount' => Money::ofTaka('500'), 'charge' => Money::zero(),
        'transferred_on' => now('Asia/Dhaka')->toDateString(), 'reference' => 'DEP-1', 'idempotency_key' => $key,
    ]))->id],
    'investment' => [Role::Accountant, fn (User $actor, string $key): int => app(RecordInvestment::class)($actor, InvestmentData::fromForm([
        'type' => 'fixed_deposit', 'name' => 'FDR', 'institution' => 'Sonali Bank', 'principal' => Money::ofTaka('500'),
        'funded_from' => 'bank', 'invested_on' => now('Asia/Dhaka')->toDateString(), 'idempotency_key' => $key,
    ]))->id],
]);

it('records one top-up when the same submission arrives several times at once', function (): void {
    $role = fn (Role $role): User => tap(User::factory()->create(), fn (User $user) => $user->assignRole($role->value));
    $accountant = $role(Role::Accountant);
    $date = now('Asia/Dhaka')->toDateString();
    app(OpenFiscalYear::class)($accountant, 2026);
    app(PostJournal::class)($accountant, simpleEntry('1111', '3101', '5000', $date));
    $investment = app(ApproveInvestment::class)($role(Role::President), app(RecordInvestment::class)($accountant, InvestmentData::fromForm([
        'type' => 'fixed_deposit', 'name' => 'FDR', 'institution' => 'Sonali Bank', 'principal' => Money::ofTaka('500'),
        'funded_from' => 'bank', 'invested_on' => $date,
    ])));
    $key = '7a2e3d4c-5b6f-4a81-9b0c-1d2e3f4a5b6c';

    $results = inParallel(4, fn (): string => 'top-up '.app(RecordInvestmentTopUp::class)(User::query()->findOrFail($accountant->id), Investment::query()->findOrFail($investment->id), InvestmentTopUpData::fromForm([
        'amount' => Money::ofTaka('200'), 'funded_from' => 'bank', 'invested_on' => $date, 'idempotency_key' => $key,
    ]))->id);

    expect(array_unique($results))->toHaveCount(1)
        ->and($results[0])->toStartWith('top-up ');
});
