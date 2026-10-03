<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\Data\SmsResult;
use App\Enums\Role;
use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\Dues;
use App\Livewire\Portal\Login;
use App\Livewire\Portal\Receipts;
use App\Livewire\Portal\SubmitPayment;
use App\Support\Bangla\BanglaNumber;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    travelTo('2026-07-05');
    $this->seed([ChartOfAccountsSeeder::class, SmsTemplateSeeder::class]);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');

    $this->sms = new class implements SmsGateway
    {
        /** @var list<array{0: string, 1: string}> */
        public array $sent = [];

        public function name(): string
        {
            return 'fake';
        }

        public function send(string $to, string $body): SmsResult
        {
            $this->sent[] = [$to, $body];

            return SmsResult::sent();
        }
    };
    app()->instance(SmsGateway::class, $this->sms);

    $this->alice = onboard(2, '2026-07', ['name_en' => 'Alice', 'name_bn' => 'আলিস', 'mobile' => '01711111111']);
    $this->bob = onboard(3, '2026-07', ['name_en' => 'Bob', 'name_bn' => 'বব', 'mobile' => '01722222222']);
    generateMonth('2026-07');
    travelTo('2026-07-05');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    RateLimiter::clear('portal-code:01711111111');
});

function lastCodeFor(object $sms, string $mobile): string
{
    $body = collect($sms->sent)->where(0, $mobile)->last()[1] ?? '';
    preg_match('/[০-৯0-9]{6}/u', $body, $match);

    return BanglaNumber::toAscii($match[0] ?? '');
}

function actAsMember($test, $member): void
{
    $test->actingAs(app(PortalAccounts::class)->forMember($member));
}

it('creates a portal login for every new member that cannot enter the staff panel', function (): void {
    $user = app(PortalAccounts::class)->forMember($this->alice);

    expect($this->alice->fresh()?->user_id)->toBe($user->id)
        ->and($user->hasRole(Role::Member->value))->toBeTrue()
        ->and($user->isStaff())->toBeFalse();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('signs a member in with an SMS code', function (): void {
    Livewire::test(Login::class)
        ->set('mobile', '+880 1711-111111')
        ->call('sendCode')
        ->assertSet('codeSent', true);

    $code = lastCodeFor($this->sms, '01711111111');

    expect($code)->toHaveLength(6);

    Livewire::test(Login::class)
        ->set('mobile', '01711111111')
        ->set('codeSent', true)
        ->set('code', $code)
        ->call('verifyCode')
        ->assertRedirect(route('portal.dashboard'));

    $this->assertAuthenticatedAs(app(PortalAccounts::class)->forMember($this->alice));
});

it('answers unknown numbers the same way without sending anything', function (): void {
    Livewire::test(Login::class)
        ->set('mobile', '01799999999')
        ->call('sendCode')
        ->assertSet('codeSent', true)
        ->assertSet('error', null);

    expect(collect($this->sms->sent)->where(0, '01799999999'))->toHaveCount(0);
});

it('locks a code after five wrong tries and limits how often codes are sent', function (): void {
    $login = Livewire::test(Login::class)->set('mobile', '01711111111')->call('sendCode');

    foreach (range(1, 5) as $try) {
        $login->set('code', '000000')->call('verifyCode');
    }

    $login->set('code', lastCodeFor($this->sms, '01711111111'))->call('verifyCode')
        ->assertSet('error', __('portal.errors.code_expired'));
    $this->assertGuest();

    Livewire::test(Login::class)->set('mobile', '01711111111')->call('sendCode')->call('sendCode')->call('sendCode')
        ->assertSet('error', fn (?string $error): bool => str_contains((string) $error, 'সেকেন্ড'));
});

it('signs in with a password the member set in their profile', function (): void {
    $user = app(PortalAccounts::class)->forMember($this->alice);
    $user->forceFill(['password' => 'correct-horse-battery'])->save();

    Livewire::test(Login::class)->set('usePassword', true)->set('mobile', '01711111111')->set('password', 'wrong-password')
        ->call('loginWithPassword')
        ->assertSet('error', __('portal.errors.wrong_password'));

    Livewire::test(Login::class)->set('usePassword', true)->set('mobile', '01711111111')->set('password', 'correct-horse-battery')
        ->call('loginWithPassword')
        ->assertRedirect(route('portal.dashboard'));
});

it('shows a member only their own money', function (): void {
    receivePayment($this->alice, '3000');  // 200 reg + 1,020 July + 1,780 advance
    actAsMember($this, $this->alice);

    // Members see the portal in their language (Bangla by default).
    Livewire::test(Dashboard::class)
        ->assertSee('৳ ১,০০০.০০')       // savings (July deposit)
        ->assertSee('৳ ১,৭৮০.০০')       // advance
        ->assertSee('জুলাই ২০২৬')
        ->assertDontSee('বব');

    Livewire::test(Dues::class)->assertSee(__('portal.dues.none'));

    actAsMember($this, $this->bob);

    Livewire::test(Dues::class)
        ->assertSee('৳ ১,৫০০.০০')       // Bob's July deposit (3 shares)
        ->assertDontSee('৳ ১,০০০.০০');

    Livewire::test(Receipts::class)->assertSee(__('portal.receipts.none'));
});

it('lets a member report a bKash payment with a screenshot for approval', function (): void {
    Storage::fake('local');
    actAsMember($this, $this->alice);

    Livewire::test(SubmitPayment::class)
        ->set('method', 'bkash')
        ->set('amount', '1,220')
        ->set('trxId', 'BKX12345Q')
        ->set('proof', UploadedFile::fake()->image('bkash.png'))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('done', fn (?string $done): bool => $done !== null);

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->member_id)->toBe($this->alice->id)
        ->and($payment->method)->toBe(PaymentMethod::Bkash)
        ->and(Storage::disk('local')->exists((string) $payment->proof_path))->toBeTrue();

    // A different user approves it; the member who submitted can't.
    expect(app(PortalAccounts::class)->forMember($this->alice)->can('approve', $payment))->toBeFalse();
});

it('requires a screenshot and never lets a member report for someone else', function (): void {
    actAsMember($this, $this->alice);

    Livewire::test(SubmitPayment::class)
        ->set('amount', '100')->set('trxId', 'BKX99999Q')
        ->call('submit')
        ->assertHasErrors(['proof' => 'required']);

    app(RecordPayment::class)(app(PortalAccounts::class)->forMember($this->alice), PaymentData::fromForm([
        'member_id' => $this->bob->id, 'method' => 'bkash', 'amount' => Money::ofTaka('100'),
        'trx_id' => 'BKX88888Q', 'received_on' => '2026-07-05', 'proof_path' => 'payment-proofs/x.png',
    ]));
})->throws(AuthorizationException::class);

it('keeps guests and staff out of the portal', function (): void {
    $this->get('/portal')->assertRedirect(route('portal.login'));

    $this->actingAs(userWithRole(Role::Accountant))->get('/portal')->assertRedirect(route('portal.login'));
});

it('renders every portal page and switches language', function (): void {
    receivePayment($this->alice, '1220');
    actAsMember($this, $this->alice);

    foreach (['portal.dashboard', 'portal.dues', 'portal.receipts', 'portal.submit', 'portal.profile'] as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->get(route('portal.dashboard'))->assertSee('আসসালামু আলাইকুম, আলিস');

    $this->post(route('portal.locale', 'en'))->assertRedirect();

    $this->get(route('portal.dashboard'))->assertSee('Assalamu Alaikum, Alice');
});
