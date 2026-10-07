<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Members\Portal\ChangeOwnPassword;
use App\Domain\Members\Portal\MemberSummary;
use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Portal\PortalAccounts;
use App\Enums\Role;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Hash;

/*
| The app's home screen (the portal's MemberSummary, unchanged), the read-only profile with
| nominees, and changing the password (which signs out the member's other devices).
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00'));
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
    $this->member = onboard(2, '2026-07', ['name_bn' => 'রহিম', 'name_en' => 'Rahim', 'nominees' => [nominee(['name' => 'Karima'])]]);
    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-07'));
    $this->token = memberToken($this->member);
});

it('shows the member summary exactly as the portal computes it, with recent payments', function (): void {
    $payment = app(RecordPayment::class)(userWithRole(Role::Cashier), PaymentData::fromForm(['member_id' => $this->member->id, 'method' => 'cash', 'amount' => '3000', 'received_on' => '2026-07-15']));
    app(ApprovePayment::class)(userWithRole(Role::Accountant), $payment);
    $summary = app(MemberSummary::class)->for($this->member);

    $this->withToken($this->token)->withHeader('Accept-Language', 'en')->getJson('/api/v1/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('data.member.member_no', $this->member->member_no)
        ->assertJsonPath('data.member.name', 'Rahim')
        ->assertJsonPath('data.savings.poisha', $summary['savings']->poisha)
        ->assertJsonPath('data.advance.poisha', $summary['advance']->poisha)
        ->assertJsonPath('data.outstanding.poisha', $summary['outstanding']->poisha)
        ->assertJsonPath('data.paid_through', $summary['paid_through'] === null ? null : substr($summary['paid_through']->toDateString(), 0, 7))
        ->assertJsonPath('data.advance_months_estimate', $summary['estimate'])
        ->assertJsonPath('data.shares', 2)
        ->assertJsonPath('data.pay_now_visible', $summary['outstanding']->isPositive())
        ->assertJsonPath('data.recent_payments.0.amount.poisha', 300000)
        ->assertJsonPath('data.recent_payments.0.status.value', 'approved');
});

it('shows the profile the portal shows, with nominees, read-only', function (): void {
    $this->withToken($this->token)->withHeader('Accept-Language', 'bn')->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.member_no', $this->member->member_no)
        ->assertJsonPath('data.name_bn', 'রহিম')
        ->assertJsonPath('data.name_en', 'Rahim')
        ->assertJsonPath('data.mobile', $this->member->mobile)
        ->assertJsonPath('data.joined_on', '2026-07-01')
        ->assertJsonPath('data.status.value', 'active')
        ->assertJsonPath('data.nominees.0.name', 'Karima')
        ->assertJsonPath('data.nominees.0.relation', 'স্বামী/স্ত্রী')
        ->assertJsonPath('data.nominees.0.share_percent', '100.00');
});

it('changes the password and signs out other devices', function (): void {
    $user = app(PortalAccounts::class)->forMember($this->member);
    $user->forceFill(['password' => 'old-pass-1'])->save();
    $other = memberToken($this->member);

    $this->withToken($this->token)->postJson('/api/v1/profile/change-password', ['current_password' => 'wrong', 'password' => 'new-pass-2', 'password_confirmation' => 'new-pass-2'])
        ->assertStatus(422)->assertJsonPath('success', false);
    $this->withToken($this->token)->postJson('/api/v1/profile/change-password', ['current_password' => 'old-pass-1', 'password' => 'new-pass-2', 'password_confirmation' => 'nope'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);

    $this->withToken($this->token)->postJson('/api/v1/profile/change-password', ['current_password' => 'old-pass-1', 'password' => 'new-pass-2', 'password_confirmation' => 'new-pass-2'])
        ->assertOk();

    expect(Hash::check('new-pass-2', (string) $user->fresh()?->password))->toBeTrue();
    app('auth')->forgetGuards();
    $this->withToken($other)->getJson('/api/v1/profile')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson('/api/v1/profile')->assertOk();
});

it('signs the app out everywhere when the member changes the password on the website', function (): void {
    $user = app(PortalAccounts::class)->forMember($this->member);
    $user->forceFill(['password' => 'old-pass-1'])->save();
    $tokens = app(MemberTokens::class)->issue($user);

    app(ChangeOwnPassword::class)($user, 'old-pass-1', 'new-pass-2');

    $this->postJson('/api/v1/auth/refresh-token', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($tokens['access_token'])->getJson('/api/v1/profile')->assertUnauthorized();
});
