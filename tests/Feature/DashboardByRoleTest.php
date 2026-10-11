<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\SaveJournalDraft;
use App\Domain\Accounting\Data\JournalDraftData;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Actions\RejectPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Members\Registration\Enums\TimelineState;
use App\Domain\Reporting\ApprovalQueue;
use App\Domain\Reporting\MemberReports;
use App\Domain\Reporting\MySubmissions;
use App\Domain\Reporting\RecordTimeline;
use App\Domain\Reporting\SomitiSnapshot;
use App\Domain\Settings\Actions\UpdateRolePermissions;
use App\Domain\Settings\Services\RolePermissions;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Member\Pages\Dashboard as MemberDashboard;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
| Each role's home page shows its own work: what waits for it, what it entered and who must act
| next, the problems it can fix, and only the figures it may see — all from the same queue and
| report rules as the rest of the app.
*/

beforeEach(function (): void {
    travelTo('2026-07-05');
    Filament::setCurrentPanel('admin');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->accountant = userWithRole(Role::Accountant);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07');
    $this->cashier = userWithRole(Role::Cashier);
    $this->president = userWithRole(Role::President);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function pendingCash(User $by, Member $member, string $taka = '200'): Payment
{
    return app(RecordPayment::class)($by, PaymentData::fromForm([
        'member_id' => $member->id, 'method' => 'cash', 'amount' => Money::ofTaka($taka), 'received_on' => '2026-07-05',
    ]));
}

it('queues an item for exactly the people the policy lets act on it', function (): void {
    $payment = pendingCash($this->cashier, $this->member);
    $queue = app(ApprovalQueue::class);

    expect($queue->forUser($this->accountant)->pluck('record.id')->all())->toContain($payment->id)
        ->and($queue->forUser($this->cashier)->all())->toBe([])
        ->and($queue->forUser(userWithRole(Role::Auditor))->all())->toBe([]);

    $item = $queue->forUser($this->accountant)->first();

    expect($queue->whoCanAct($item)->pluck('id')->all())
        ->toContain($this->accountant->id, $this->president->id)
        ->not->toContain($this->cashier->id);
});

it('shows the cashier what they entered and who it is waiting for', function (): void {
    pendingCash($this->cashier, $this->member);

    $rows = app(MySubmissions::class)->recent($this->cashier);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->reference)->toBe($this->member->displayName())
        ->and($rows[0]->waitingFor)->toContain($this->accountant->name)
        ->and($rows[0]->waitingFor)->toContain($this->president->name);

    $this->actingAs($this->cashier)->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee(__('dashboard.my_submissions', [], 'bn'))
        ->assertSee($this->accountant->name);
});

it('drops the "waiting for" once a payment is approved', function (): void {
    app(ApprovePayment::class)($this->accountant, pendingCash($this->cashier, $this->member));

    expect(app(MySubmissions::class)->recent($this->cashier)[0]->waitingFor)->toBeNull();
});

it('warns when nobody is able to approve a waiting item', function (): void {
    $payment = pendingCash($this->cashier, $this->member);

    $grid = app(RolePermissions::class)->grid();
    foreach (Permission::editableRoles() as $role) {
        $grid[Permission::PaymentsApprove->value][$role->value] = $role === Role::Cashier;
    }
    app(UpdateRolePermissions::class)($this->president, $grid);

    // Only cashiers may approve now, and the one cashier entered it.
    $other = User::query()->whereKeyNot($this->cashier->id)->get()->filter(fn (User $user): bool => $user->hasAnyOf(Role::Cashier));
    $other->each(fn (User $user) => $user->forceFill(['deactivated_at' => now()])->save());

    $steps = app(RecordTimeline::class)->steps($payment->fresh());

    expect(end($steps)->label)->toBe(__('timeline.nobody_can_act'))
        ->and(app(MySubmissions::class)->recent($this->cashier)[0]->waitingFor)->toBe(__('dashboard.nobody_can_act'));
});

it('adds the registration and voucher queues to "waiting for you"', function (): void {
    app(SaveJournalDraft::class)($this->accountant, null, JournalDraftData::fromForm([
        'voucher_type' => 'CV', 'entry_date' => '2026-07-05', 'narration' => 'Cash to bank',
        'lines' => [
            ['account_id' => account('1111')->id, 'debit' => Money::ofTaka('10'), 'credit' => null],
            ['account_id' => account('1101')->id, 'debit' => null, 'credit' => Money::ofTaka('10')],
        ],
    ]));

    $this->actingAs($this->president)->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee(__('dashboard.vouchers_to_post', [], 'bn'))
        ->assertSee(__('dashboard.registrations_to_decide', [], 'bn'));
});

it('counts overdue members by the same rule as the Defaulters report', function (): void {
    $paid = onboard(1, '2026-07');
    generateMonth('2026-07');
    travelTo('2026-07-05');
    app(ApprovePayment::class)($this->accountant, pendingCash($this->cashier, $paid, '610'));
    travelTo('2026-08-20');
    $today = CarbonImmutable::now('Asia/Dhaka');

    expect(app(SomitiSnapshot::class)->membersOverdue($today))
        ->toBe(count(app(MemberReports::class)->defaulters($today)))
        ->toBeGreaterThan(0);
});

it('gives each role its own quick buttons and widgets', function (): void {
    $this->actingAs($this->cashier);
    Livewire::test(Dashboard::class)->assertActionVisible('collect')->assertActionDoesNotExist('auditLog');
    $this->get(Dashboard::getUrl())->assertDontSee(__('dashboard.alerts', [], 'bn'));

    $this->actingAs(userWithRole(Role::Auditor));
    Livewire::test(Dashboard::class)->assertActionVisible('auditLog')->assertActionDoesNotExist('collect');
    $this->get(Dashboard::getUrl())
        ->assertDontSee(__('dashboard.my_submissions', [], 'bn'))
        ->assertSee(__('dashboard.alerts', [], 'bn'));

    $this->actingAs($this->accountant)->get(Dashboard::getUrl())
        ->assertSee(__('dashboard.integrity', [], 'bn'))
        ->assertDontSee(__('dashboard.failed_sms', [], 'bn'));
});

it('shows only steps that happened in a record’s history', function (): void {
    $payment = pendingCash($this->cashier, $this->member);
    $timeline = app(RecordTimeline::class);

    $pending = $timeline->steps($payment);
    expect(array_map(fn ($step) => $step->state, $pending))->toBe([TimelineState::Done, TimelineState::Pending])
        ->and($pending[0]->actorName)->toBe($this->cashier->name);

    app(RejectPayment::class)($this->accountant, $payment, 'Cash was counted short');
    $rejected = $timeline->steps($payment->fresh());

    expect(array_map(fn ($step) => $step->state, $rejected))->toBe([TimelineState::Done, TimelineState::Rejected])
        ->and($rejected[1]->actorName)->toBe($this->accountant->name)
        ->and($rejected[1]->reason)->toBe('Cash was counted short')
        ->and($rejected[1]->actedAt)->not->toBeNull();
});

it('tells the member on the portal about payments being checked and turned down', function (): void {
    Filament::setCurrentPanel('member');
    $user = app(PortalAccounts::class)->forMember($this->member);
    pendingCash($this->cashier, $this->member->fresh());
    $rejected = pendingCash($this->cashier, $this->member->fresh(), '300');
    app(RejectPayment::class)($this->accountant, $rejected, 'Wrong TrxID entered');

    $this->actingAs($user)->get(MemberDashboard::getUrl())
        ->assertOk()
        ->assertSee(__('portal.alerts.pending_heading', ['count' => '১'], 'bn'))
        ->assertSee('Wrong TrxID entered');
});
