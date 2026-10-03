<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Models\PaymentAllocation;
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

it('posts a payment exactly once when two checkers approve it at the same time (P5 scenario 6)', function (): void {
    $staff = fn (Role $role): User => tap(User::factory()->create(), fn (User $user) => $user->assignRole($role->value));

    $accountant = $staff(Role::Accountant);
    app(OpenFiscalYear::class)($accountant, 2026);
    approvedPlan('2026-07', '500');
    $member = onboard(1, '2026-07');

    $payment = app(RecordPayment::class)($staff(Role::Cashier), PaymentData::fromForm([
        'member_id' => $member->id,
        'method' => 'cash',
        'amount' => Money::ofTaka('600'),
        'received_on' => now('Asia/Dhaka')->toDateString(),
    ]));

    $checkers = [$staff(Role::Accountant)->id, $staff(Role::President)->id, $staff(Role::Accountant)->id, $staff(Role::President)->id];

    $results = inParallel(count($checkers), function (int $index) use ($checkers, $payment): string {
        app(ApprovePayment::class)(User::query()->findOrFail($checkers[$index]), Payment::query()->findOrFail($payment->id));

        return 'approved';
    });

    expect(array_count_values($results)['approved'] ?? 0)->toBe(1)
        ->and(JournalEntry::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Approved);
});
