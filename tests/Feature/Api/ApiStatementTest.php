<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Enums\Role;
use App\Reports\Definitions\MemberStatementReport;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

/*
| The portal's member statement in the app: the same report, always for the signed-in member,
| as JSON and as a PDF.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-20 10:00'));
    $this->seed(ChartOfAccountsSeeder::class);
    app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07');
    $this->other = onboard(1, '2026-07');
    app(GenerateMonthlyDues::class)(YearMonth::parse('2026-07'));
    $payment = app(RecordPayment::class)(userWithRole(Role::Cashier), PaymentData::fromForm(['member_id' => $this->member->id, 'method' => 'cash', 'amount' => '700', 'received_on' => '2026-07-15']));
    app(ApprovePayment::class)(userWithRole(Role::Accountant), $payment);
    $this->token = memberToken($this->member);
});

it('returns the same statement as the portal for the member, ignoring any member parameter', function (): void {
    $expected = app(MemberStatementReport::class)->data(['member' => $this->member->id, 'from' => '2026-07-01', 'until' => '2026-07-31']);
    $data = $this->withToken($this->token)->getJson("/api/v1/statement?from=2026-07-01&until=2026-07-31&member={$this->other->id}")->assertOk()->json('data');

    expect($expected)->not->toBeNull()
        ->and($data['opening']['poisha'])->toBe($expected['opening']->poisha)
        ->and($data['closing']['poisha'])->toBe($expected['closing']->poisha)
        ->and($data['total_charges']['poisha'])->toBe($expected['total_charges']->poisha)
        ->and($data['total_paid']['poisha'])->toBe($expected['total_paid']->poisha)
        ->and($data['total_paid']['poisha'])->toBe(70000)
        ->and(count($data['rows']))->toBe(count($expected['rows']))
        ->and($data['rows'][0])->toHaveKeys(['date', 'description', 'charge', 'paid', 'balance'])
        ->and($data['from'])->toBe('2026-07-01')
        ->and($data['until'])->toBe('2026-07-31');
});

it('defaults to the fiscal year so far', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/statement')->assertOk()
        ->assertJsonPath('data.from', '2026-07-01')
        ->assertJsonPath('data.until', '2026-07-20');
});

it('downloads the statement PDF', function (): void {
    $this->withToken($this->token)->get('/api/v1/statement/pdf?from=2026-07-01&until=2026-07-31')
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('rejects a reversed or malformed date range', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/statement?from=2026-07-31&until=2026-07-01')->assertStatus(422)->assertJsonStructure(['errors' => ['until']]);
    $this->withToken($this->token)->getJson('/api/v1/statement?from=31/07/2026')->assertStatus(422);
});

it('gives a short-lived signed link to the statement PDF that works without a token', function (): void {
    $url = $this->withToken($this->token)->getJson('/api/v1/statement/pdf-link?from=2026-07-01&until=2026-07-31')->assertOk()->json('data.url');

    app('auth')->forgetGuards();
    $this->withToken('')->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->withToken('')->get(str_replace('until=2026-07-31', 'until=2026-08-31', $url))->assertForbidden();
    $this->withToken('')->get(preg_replace('/member=\d+/', 'member='.$this->other->id, $url))->assertForbidden();

    $this->travel(16)->minutes();
    $this->withToken('')->get($url)->assertForbidden();
});
