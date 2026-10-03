<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Contributions\Services\AdvanceLedger;
use App\Enums\Role;
use App\Filament\Pages\Collections\AdvanceBalances;
use App\Filament\Pages\Collections\CollectPayment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Reports\ReceiptDocument;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    app()->setLocale('en');
    travelTo('2026-07-05');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
    $this->member = onboard(2, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    $this->cashier = userWithRole(Role::Cashier);
    $this->accountant = userWithRole(Role::Accountant);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function pendingPayment($test, string $taka = '1500'): Payment
{
    return app(RecordPayment::class)($test->cashier, PaymentData::fromForm([
        'member_id' => $test->member->id, 'method' => 'cash', 'amount' => Money::ofTaka($taka), 'received_on' => '2026-07-05',
    ]));
}

it('shows the member\'s dues and previews what a payment will settle', function (): void {
    $this->actingAs($this->cashier);

    Livewire::test(CollectPayment::class)
        ->assertSee(__('payments.collect.pick_member'))
        ->fillForm(['member_id' => $this->member->id, 'amount' => '3,000'])
        ->assertSee('৳ 1,220.00')   // registration 200 + July 1,020
        ->assertSee(__('payments.collect.to_dues', ['amount' => '৳ 1,220.00']))
        ->assertSee('৳ 1,780.00 held as advance → covers through August 2026 at current rates (estimate)');
});

it('records a bKash payment with proof for approval', function (): void {
    Storage::fake('local');
    $this->actingAs($this->cashier);

    Livewire::test(CollectPayment::class)
        ->fillForm([
            'member_id' => $this->member->id,
            'method' => 'bkash',
            'amount' => '1,220',
            'trx_id' => 'BK9X2Z7Q',
            'proof_path' => UploadedFile::fake()->image('slip.png'),
        ])
        ->callAction('record')
        ->assertHasNoFormErrors()
        ->assertNotified(__('payments.collect.saved', ['amount' => '৳ 1,220.00']))
        ->assertSchemaStateSet(['member_id' => null]);

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->trx_id)->toBe('BK9X2Z7Q')
        ->and(Storage::disk('local')->exists((string) $payment->proof_path))->toBeTrue();
});

it('requires a transaction id for mobile money', function (): void {
    $this->actingAs($this->cashier);

    Livewire::test(CollectPayment::class)
        ->fillForm(['member_id' => $this->member->id, 'method' => 'nagad', 'amount' => '100'])
        ->callAction('record')
        ->assertHasFormErrors(['trx_id' => 'required']);
});

it('approves with the member number typed and offers the receipt', function (): void {
    $payment = pendingPayment($this);
    $this->actingAs($this->accountant);

    Livewire::test(ListPayments::class)
        ->assertCanSeeTableRecords([$payment])
        ->callAction(TestAction::make('approve')->table($payment), data: ['confirm_text' => 'wrong'])
        ->assertHasActionErrors(['confirm_text']);

    Livewire::test(ListPayments::class)
        ->callAction(TestAction::make('approve')->table($payment), data: ['confirm_text' => $this->member->member_no])
        ->assertHasNoActionErrors()
        ->assertNotified(__('payments.actions.approved', ['voucher' => 'RV-2026-27-000001']));

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Approved);

    $this->get(ReceiptDocument::signedUrl($payment->fresh()))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get(route('receipts.show', $payment))->assertForbidden();
});

it('hides approval from the person who recorded the payment', function (): void {
    $payment = pendingPayment($this);
    $this->actingAs($this->cashier);

    Livewire::test(ListPayments::class)
        ->assertActionHidden(TestAction::make('approve')->table($payment))
        ->assertActionVisible(TestAction::make('cancel')->table($payment));
});

it('approves the selected payments in bulk', function (): void {
    $first = pendingPayment($this, '500');
    $second = pendingPayment($this, '700');
    $this->actingAs($this->accountant);

    Livewire::test(ListPayments::class)
        ->selectTableRecords([$first->id, $second->id])
        ->callAction(TestAction::make('bulkApprove')->table()->bulk(), data: ['confirm_text' => 'CONFIRM'])
        ->assertNotified(__('payments.actions.bulk_result', ['approved' => '2', 'failed' => '0']));

    expect(Payment::query()->where('status', PaymentStatus::Approved)->count())->toBe(2);
});

it('reverses an approved payment from its page', function (): void {
    $payment = pendingPayment($this);
    app(ApprovePayment::class)($this->accountant, $payment);
    $this->actingAs(userWithRole(Role::President));

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('reverse', data: ['reason' => 'Duplicate entry', 'confirm_text' => 'RV-2026-27-000001'])
        ->assertHasNoActionErrors();

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Reversed);
    assertBooksTieOut();
});

it('lists advance balances and refunds after the member number is typed', function (): void {
    receivePayment($this->member, '3220'); // 1,220 dues, 2,000 advance
    $this->actingAs($this->accountant);

    Livewire::test(AdvanceBalances::class)
        ->assertCanSeeTableRecords([$this->member])
        ->assertSee('৳ 2,000.00')
        ->callAction(TestAction::make('refund')->table($this->member), data: [
            'amount' => '500', 'paid_from' => 'cash', 'reason' => 'Member needs cash', 'confirm_text' => $this->member->member_no,
        ])
        ->assertHasNoActionErrors();

    expect(app(AdvanceLedger::class)->balance($this->member->id)->poisha)->toBe(150000);
});

it('shows the pending count on the menu and renders in Bangla', function (): void {
    pendingPayment($this);
    pendingPayment($this, '200');
    $this->actingAs($this->accountant);

    expect(PaymentResource::getNavigationBadge())->toBe('2');

    app()->setLocale('bn');
    $this->get(PaymentResource::getUrl('index'))->assertOk()->assertSee('অপেক্ষমাণ');
    $this->get(CollectPayment::getUrl())->assertOk()->assertSee('আদায় গ্রহণ');
});
