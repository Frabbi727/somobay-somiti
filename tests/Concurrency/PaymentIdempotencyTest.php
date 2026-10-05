<?php

declare(strict_types=1);

use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Portal\PortalAccounts;
use App\Models\User;
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

it('records one payment when the app retries a submission while the first is still running', function (): void {
    approvedPlan('2026-07', '500');
    $member = onboard(1, '2026-07');
    $userId = app(PortalAccounts::class)->forMember($member)->id;
    $key = '0b9e3c1a-7d52-4f0e-a1c4-5e8f2d6b9a31';

    $results = inParallel(4, function () use ($member, $userId, $key): string {
        $payment = app(RecordPayment::class)(User::query()->findOrFail($userId), PaymentData::fromForm([
            'member_id' => $member->id, 'method' => 'bkash', 'amount' => '500', 'trx_id' => 'BK12345678',
            'received_on' => now('Asia/Dhaka')->toDateString(), 'idempotency_key' => $key, 'proof_path' => 'payment-proofs/p.jpg',
        ]));

        return 'payment '.$payment->id;
    });

    $id = Payment::query()->sole()->id;

    expect($results)->toBe(array_fill(0, 4, 'payment '.$id));
});
