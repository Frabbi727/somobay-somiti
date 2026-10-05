<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Models\Member;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Payments in the app: the member's own list and detail (with how each payment was allocated),
| receipt links for approved payments, and the portal's Pay Online submission (bKash/Nagad with
| proof, pending until staff approve), safe to retry with the same idempotency key.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00'));
    Storage::fake('local');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07');
    $this->other = onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-07'));
    $this->token = memberToken($this->member);
});

function cashPayment(Member $member, string $amount, bool $approve = true): Payment
{
    $payment = app(RecordPayment::class)(userWithRole(Role::Cashier), PaymentData::fromForm(['member_id' => $member->id, 'method' => 'cash', 'amount' => $amount, 'received_on' => '2026-07-15']));

    return $approve ? app(ApprovePayment::class)(userWithRole(Role::Accountant), $payment) : $payment;
}

/**
 * @return array<string, mixed>
 */
function payOnlineBody(string $key, string $amount = '500', array $overrides = []): array
{
    return ['method' => 'bkash', 'amount' => $amount, 'trx_id' => 'abc12345x', 'received_on' => '2026-07-15', 'idempotency_key' => $key, 'proof' => UploadedFile::fake()->image('proof.jpg'), ...$overrides];
}

it('lists and shows the member\'s own payments with how they were allocated', function (): void {
    $payment = cashPayment($this->member, '3000');

    $this->withToken($this->token)->getJson('/api/v1/payments')->assertOk()->assertJsonPath('data.0.id', $payment->id)->assertJsonPath('meta.total', 1);

    $detail = $this->withToken($this->token)->getJson("/api/v1/payments/{$payment->id}")->assertOk()->json('data');
    $allocated = collect($detail['allocations'])->sum('amount.poisha');

    expect($detail['amount']['poisha'])->toBe(300000)
        ->and($allocated + $detail['to_advance']['poisha'])->toBe(300000)
        ->and($detail['to_advance']['poisha'])->toBeGreaterThan(0)
        ->and($detail['allocations'][0])->toHaveKeys(['due_id', 'month', 'type', 'amount'])
        ->and($detail['receipt_available'])->toBeTrue();
});

it('never shows another member\'s payment or receipt', function (): void {
    $theirs = cashPayment($this->other, '500');

    $this->withToken($this->token)->getJson("/api/v1/payments/{$theirs->id}")->assertNotFound()->assertJsonPath('data', null);
    $this->withToken($this->token)->getJson("/api/v1/payments/{$theirs->id}/receipt")->assertNotFound();
    expect(collect($this->withToken($this->token)->getJson('/api/v1/payments')->json('data'))->pluck('id')->all())->not->toContain($theirs->id);
});

it('gives a working receipt link for approved payments only', function (): void {
    $approved = cashPayment($this->member, '500');
    $pending = cashPayment($this->member, '100', approve: false);

    $url = $this->withToken($this->token)->getJson("/api/v1/payments/{$approved->id}/receipt")->assertOk()->json('data.url');
    $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->withToken($this->token)->getJson("/api/v1/payments/{$pending->id}/receipt")->assertNotFound();
});

it('submits a bKash payment with proof as pending, exactly once per key', function (): void {
    $key = (string) Str::uuid();

    $first = $this->withToken($this->token)->post('/api/v1/payments', payOnlineBody($key, '১,০০০.৫০'), ['Accept' => 'application/json'])->assertCreated()->json('data');
    $again = $this->withToken($this->token)->post('/api/v1/payments', payOnlineBody($key, '1000.50'), ['Accept' => 'application/json'])->assertCreated()->json('data');

    expect($first['id'])->toBe($again['id'])
        ->and($first['status']['value'])->toBe('pending')
        ->and($first['amount']['poisha'])->toBe(100050)
        ->and($first['trx_id'])->toBe('ABC12345X')
        ->and(Payment::query()->where('member_id', $this->member->id)->count())->toBe(1)
        ->and(Storage::disk('local')->files('payment-proofs'))->toHaveCount(1);

    $this->withToken($this->token)->post('/api/v1/payments', payOnlineBody($key, '999'), ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('success', false);
    expect(Storage::disk('local')->files('payment-proofs'))->toHaveCount(1);
});

it('accepts a bKash payment without proof (proof is optional in the app)', function (): void {
    $body = array_diff_key(payOnlineBody((string) Str::uuid(), '500'), ['proof' => true]);

    $data = $this->withToken($this->token)->post('/api/v1/payments', $body, ['Accept' => 'application/json'])->assertCreated()->json('data');

    expect($data['status']['value'])->toBe('pending')
        ->and(Payment::query()->findOrFail($data['id'])->proof_path)->toBeNull()
        ->and(Storage::disk('local')->files('payment-proofs'))->toBe([]);
});

it('validates the payment form like the portal', function (array $override, string $field): void {
    $this->withToken($this->token)->post('/api/v1/payments', payOnlineBody((string) Str::uuid(), '500', $override), ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);

    expect(Storage::disk('local')->files('payment-proofs'))->toBe([]);
})->with([
    'cash not allowed' => [['method' => 'cash'], 'method'],
    'three decimals' => [['amount' => '10.555'], 'amount'],
    'zero' => [['amount' => '0'], 'amount'],
    'trx format' => [['trx_id' => 'ab'], 'trx_id'],
    'wrong file type' => [['proof' => UploadedFile::fake()->create('p.exe', 10, 'application/x-msdownload')], 'proof'],
    'not a uuid' => [['idempotency_key' => 'x'], 'idempotency_key'],
]);
