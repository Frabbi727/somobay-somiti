<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Enums\Role;
use App\Reports\ReceiptDocument;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| A receipt prints the member's position as it was at approval (design 2026-11-22 §4): later
| payments, reversals or rate changes never change an old receipt.
*/

beforeEach(function (): void {
    travelTo('2026-07-01');
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500', ['service_charge_per_share_poisha' => Money::zero(), 'registration_fee_per_share_poisha' => Money::zero()]);
    $this->member = onboard(2, '2026-07');

    foreach (['2026-07', '2026-08', '2026-09'] as $month) {
        generateMonth($month);
    }

    travelTo('2026-09-15');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('freezes owed before and after, advance and paid-through at approval', function (): void {
    $first = receivePayment($this->member, '1500');

    expect($first->outstanding_before_poisha?->poisha)->toBe(300000)
        ->and($first->outstanding_after_poisha?->poisha)->toBe(150000)
        ->and($first->other_owed_after_poisha?->poisha)->toBe(0)
        ->and($first->advance_after_poisha?->poisha)->toBe(0)
        ->and($first->paid_through_after?->equals(YearMonth::parse('2026-07')))->toBeTrue();

    receivePayment($this->member, '2000');
    $first->refresh();

    expect($first->outstanding_after_poisha?->poisha)->toBe(150000)
        ->and($first->paid_through_after?->equals(YearMonth::parse('2026-07')))->toBeTrue();
});

it('refuses to change frozen figures in the database', function (): void {
    $payment = receivePayment($this->member, '1500');

    expect(fn () => DB::table('payments')->where('id', $payment->id)->update(['outstanding_after_poisha' => 0]))
        ->toThrow(QueryException::class, 'frozen');
});

it('prints the frozen figures on the receipt', function (): void {
    $payment = receivePayment($this->member, '1500');
    receivePayment($this->member, '1500');

    $this->get(ReceiptDocument::signedUrl($payment))->assertOk();
});
