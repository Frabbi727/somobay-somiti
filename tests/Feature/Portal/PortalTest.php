<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\Data\SmsResult;
use App\Enums\Role;
use App\Filament\Member\Pages\Auth\MemberLogin;
use App\Filament\Member\Pages\Dashboard;
use App\Filament\Member\Pages\Dividends;
use App\Filament\Member\Pages\Dues;
use App\Filament\Member\Pages\Payments;
use App\Filament\Member\Pages\PayOnline;
use App\Filament\Member\Pages\Profile;
use App\Filament\Member\Pages\Statement;
use App\Filament\Member\Widgets\MemberStatsWidget;
use App\Filament\Member\Widgets\RecentPaymentsWidget;
use App\Reports\ReceiptDocument;
use App\Support\Bangla\BanglaNumber;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SmsTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    travelTo('2026-07-05');
    Filament::setCurrentPanel('member');
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
    RateLimiter::clear('livewire-rate-limiter:'.sha1(MemberLogin::class.'|authenticate|127.0.0.1'));
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

it('signs a member in with an SMS code when codes are switched on', function (): void {
    Livewire::test(MemberLogin::class)
        ->fillForm(['mobile' => '+880 1711-111111', 'method' => 'code'])
        ->call('sendCode')
        ->assertNotified(__('portal.login.code_sent'));

    $code = lastCodeFor($this->sms, '01711111111');

    expect($code)->toHaveLength(6);

    Livewire::test(MemberLogin::class)
        ->fillForm(['mobile' => '01711111111', 'method' => 'code', 'code' => $code])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(Dashboard::getUrl());

    $this->assertAuthenticatedAs(app(PortalAccounts::class)->forMember($this->alice));
});

it('answers unknown numbers the same way without sending anything', function (): void {
    Livewire::test(MemberLogin::class)
        ->fillForm(['mobile' => '01799999999', 'method' => 'code'])
        ->call('sendCode')
        ->assertNotified(__('portal.login.code_sent'));

    expect(collect($this->sms->sent)->where(0, '01799999999'))->toHaveCount(0);
});

it('locks a code after five wrong tries and limits how often codes are sent', function (): void {
    Livewire::test(MemberLogin::class)->fillForm(['mobile' => '01711111111', 'method' => 'code'])->call('sendCode');

    foreach (range(1, 5) as $try) {
        Livewire::test(MemberLogin::class)->fillForm(['mobile' => '01711111111', 'method' => 'code', 'code' => '000000'])->call('authenticate');
    }

    RateLimiter::clear('livewire-rate-limiter:'.sha1(MemberLogin::class.'|authenticate|127.0.0.1'));

    Livewire::test(MemberLogin::class)
        ->fillForm(['mobile' => '01711111111', 'method' => 'code', 'code' => lastCodeFor($this->sms, '01711111111')])
        ->call('authenticate')
        ->assertHasFormErrors(['code']);
    $this->assertGuest();

    Livewire::test(MemberLogin::class)->fillForm(['mobile' => '01711111111', 'method' => 'code'])->call('sendCode')->call('sendCode')->call('sendCode')
        ->assertHasFormErrors(['mobile']);
});

it('signs in with mobile and password, and refuses a wrong password', function (): void {
    app(PortalAccounts::class)->forMember($this->alice)->forceFill(['password' => 'correct-horse'])->save();

    Livewire::test(MemberLogin::class)
        ->fillForm(['mobile' => '01711111111', 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['mobile']);
    $this->assertGuest();

    Livewire::test(MemberLogin::class)
        ->fillForm(['mobile' => '01711111111', 'password' => 'correct-horse'])
        ->call('authenticate')
        ->assertRedirect(Dashboard::getUrl());
});

it('stops guessing after five failed sign-ins', function (): void {
    foreach (range(1, 5) as $try) {
        Livewire::test(MemberLogin::class)->fillForm(['mobile' => '01711111111', 'password' => 'wrong'])->call('authenticate');
    }

    app(PortalAccounts::class)->forMember($this->alice)->forceFill(['password' => 'correct-horse'])->save();

    Livewire::test(MemberLogin::class)
        ->fillForm(['mobile' => '01711111111', 'password' => 'correct-horse'])
        ->call('authenticate')
        ->assertNoRedirect();
    $this->assertGuest();
});

it('shows a member only their own money', function (): void {
    receivePayment($this->alice, '3000');  // 200 reg + 1,020 July + 1,780 advance
    receivePayment($this->bob, '50');
    actAsMember($this, $this->alice);

    // Members see the portal in their language (Bangla by default).
    Livewire::test(MemberStatsWidget::class)
        ->assertSee('৳ ১,০০০.০০')       // savings (July deposit)
        ->assertSee('৳ ১,৭৮০.০০')       // advance
        ->assertSee('জুলাই ২০২৬');

    $alicePayments = Payment::query()->where('member_id', $this->alice->id)->get();
    $bobPayments = Payment::query()->where('member_id', $this->bob->id)->get();

    Livewire::test(RecentPaymentsWidget::class)->assertCanSeeTableRecords($alicePayments)->assertCanNotSeeTableRecords($bobPayments);
    Livewire::test(Payments::class)->assertCanSeeTableRecords($alicePayments)->assertCanNotSeeTableRecords($bobPayments);
    Livewire::test(Dues::class)->assertCountTableRecords(0);

    actAsMember($this, $this->bob);

    Livewire::test(Dues::class)
        ->assertCanSeeTableRecords(Due::query()->where('member_id', $this->bob->id)->where('status', 'open')->get())
        ->assertCanNotSeeTableRecords(Due::query()->where('member_id', $this->alice->id)->get());
});

it('offers a receipt only for approved payments', function (): void {
    $approved = receivePayment($this->alice, '1220');
    $pending = app(RecordPayment::class)(app(PortalAccounts::class)->forMember($this->alice), PaymentData::fromForm([
        'member_id' => $this->alice->id, 'method' => 'bkash', 'amount' => Money::ofTaka('100'),
        'trx_id' => 'BKX77777Q', 'received_on' => '2026-07-05', 'proof_path' => 'payment-proofs/x.png',
    ]));
    actAsMember($this, $this->alice);

    Livewire::test(Payments::class)
        ->assertTableActionVisible('receipt', $approved)
        ->assertTableActionHidden('receipt', $pending)
        ->assertSee(e(ReceiptDocument::signedUrl($approved)), escape: false);
});

it('lets a member report a bKash payment with a screenshot for approval', function (): void {
    Storage::fake('local');
    actAsMember($this, $this->alice);

    Livewire::test(PayOnline::class)
        ->fillForm([
            'method' => 'bkash',
            'amount' => '1,220',
            'trx_id' => 'bkx12345q',
            'proof_path' => UploadedFile::fake()->image('bkash.png'),
        ])
        ->callAction('submit')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->member_id)->toBe($this->alice->id)
        ->and($payment->method)->toBe(PaymentMethod::Bkash)
        ->and($payment->trx_id)->toBe('BKX12345Q')
        ->and(Storage::disk('local')->exists((string) $payment->proof_path))->toBeTrue();

    // A different user approves it; the member who submitted can't.
    expect(app(PortalAccounts::class)->forMember($this->alice)->can('approve', $payment))->toBeFalse();
});

it('requires a screenshot and never lets a member report for someone else', function (): void {
    actAsMember($this, $this->alice);

    Livewire::test(PayOnline::class)
        ->fillForm(['amount' => '100', 'trx_id' => 'BKX99999Q'])
        ->callAction('submit')
        ->assertHasFormErrors(['proof_path' => 'required']);

    expect(Payment::query()->count())->toBe(0);

    app(RecordPayment::class)(app(PortalAccounts::class)->forMember($this->alice), PaymentData::fromForm([
        'member_id' => $this->bob->id, 'method' => 'bkash', 'amount' => Money::ofTaka('100'),
        'trx_id' => 'BKX88888Q', 'received_on' => '2026-07-05', 'proof_path' => 'payment-proofs/x.png',
    ]));
})->throws(AuthorizationException::class);

it('downloads the member own statement whatever member id the browser sends', function (): void {
    receivePayment($this->alice, '1220');
    actAsMember($this, $this->alice);

    Livewire::test(Statement::class)
        ->set('filters.member', $this->bob->id)
        ->assertSee('আলিস')
        ->assertDontSee('বব')
        ->callAction('pdf')
        ->assertFileDownloaded();
});

it('lets a member change their own password with the current one', function (): void {
    $user = app(PortalAccounts::class)->forMember($this->alice);
    $user->forceFill(['password' => 'old-secret'])->save();
    actAsMember($this, $this->alice);

    Livewire::test(Profile::class)
        ->fillForm(['current' => 'wrong', 'password' => 'new-secret', 'password_confirmation' => 'new-secret'])
        ->callAction('changePassword')
        ->assertNotified(__('portal.errors.current_password'));

    expect(Hash::check('old-secret', (string) $user->fresh()?->password))->toBeTrue();

    Livewire::test(Profile::class)
        ->fillForm(['current' => 'old-secret', 'password' => 'new-secret', 'password_confirmation' => 'new-secret'])
        ->callAction('changePassword')
        ->assertNotified(__('portal.profile.password_saved'));

    expect(Hash::check('new-secret', (string) $user->fresh()?->password))->toBeTrue();
});

it('keeps guests and staff out of the portal and members out of the staff panel', function (): void {
    $this->get('/portal')->assertRedirect(route('filament.member.auth.login'));

    $this->actingAs(userWithRole(Role::Accountant))->get('/portal')->assertForbidden();

    actAsMember($this, $this->alice);
    $this->get('/admin')->assertForbidden();
});

it('renders every portal page in the sidebar and follows the member language', function (): void {
    receivePayment($this->alice, '1220');
    actAsMember($this, $this->alice);

    foreach ([Dashboard::class, Dues::class, Payments::class, PayOnline::class, Statement::class, Dividends::class, Profile::class] as $page) {
        $this->get($page::getUrl())->assertOk()->assertSee($page::getNavigationLabel());
    }

    $this->get(Dashboard::getUrl())->assertSee('আসসালামু আলাইকুম, আলিস');

    auth()->user()?->forceFill(['locale' => 'en'])->save();

    $this->get(Dashboard::getUrl())->assertSee('Assalamu Alaikum, Alice');
});

it('never hands out a link to another file on the private disk through the proof upload', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('Somiti Manager/secret.zip', 'backup');
    actAsMember($this, $this->alice);

    $page = Livewire::test(PayOnline::class)->set('data.proof_path', ['tampered' => 'Somiti Manager/secret.zip']);
    $key = $page->instance()->form->getFlatFields(withHidden: true)['proof_path']->getKey();

    $urls = $page->call('callSchemaComponentMethod', $key, 'getUploadedFiles')->effects['returns'][0] ?? null;

    expect($urls)->toBe(['tampered' => null]);

    $page->fillForm(['amount' => '100', 'trx_id' => 'BKX99999Q'])
        ->set('data.proof_path', ['tampered' => 'Somiti Manager/secret.zip'])
        ->callAction('submit')
        ->assertHasFormErrors(['proof_path']);

    expect(Payment::query()->count())->toBe(0);
});
