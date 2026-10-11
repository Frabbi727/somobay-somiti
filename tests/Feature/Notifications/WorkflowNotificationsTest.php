<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\ApproveExpense;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournalDraft;
use App\Domain\Accounting\Actions\RecordExpense;
use App\Domain\Accounting\Actions\SaveJournalDraft;
use App\Domain\Accounting\Data\ExpenseData;
use App\Domain\Accounting\Data\JournalDraftData;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Actions\RejectPayment;
use App\Domain\Contributions\Actions\ReversePayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Notifications\Actions\ResendSms;
use App\Domain\Notifications\Actions\SendApprovalReminders;
use App\Domain\Notifications\Enums\SmsStatus;
use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Jobs\SendSmsJob;
use App\Domain\Notifications\Models\SmsMessage;
use App\Domain\Notifications\Services\WorkflowNotifier;
use App\Domain\Settings\Actions\CreateStaffUser;
use App\Domain\Settings\Data\StaffUserData;
use App\Enums\Role;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
| The bell: each step of an approval tells exactly the people who must act next, or who entered
| the item — in their own language, with a link, and only once the action has committed.
*/

beforeEach(function (): void {
    travelTo('2026-07-05');
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(SmsTemplateSeeder::class);
    fakeSms();
    $this->accountant = userWithRole(Role::Accountant);
    app(OpenFiscalYear::class)($this->accountant, 2026);
    approvedPlan('2026-07', '500');
    $this->member = onboard(1, '2026-07');
    $this->cashier = userWithRole(Role::Cashier);
    $this->president = userWithRole(Role::President);
    $this->secretary = userWithRole(Role::Secretary);
    $this->auditor = userWithRole(Role::Auditor);
    $this->admin = userWithRole(Role::SuperAdmin);
    DB::table('notifications')->delete();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return list<string>
 */
function bellTitles(User $user): array
{
    return $user->fresh()?->notifications()->oldest()->get()->map(fn ($notification): string => (string) $notification->data['title'])->all() ?? [];
}

function recordCash(User $by, Member $member, string $taka = '200'): Payment
{
    return app(RecordPayment::class)($by, PaymentData::fromForm([
        'member_id' => $member->id, 'method' => 'cash', 'amount' => Money::ofTaka($taka), 'received_on' => '2026-07-05',
    ]));
}

it('tells only the people who can approve a new payment, in their own language, with a link', function (): void {
    $this->accountant->update(['locale' => 'en']);
    $this->president->update(['locale' => 'bn']);

    $payment = recordCash($this->cashier, $this->member);

    expect(bellTitles($this->accountant))->toBe([__('notifications.payment.submitted.title', [], 'en')])
        ->and(bellTitles($this->president))->toBe([__('notifications.payment.submitted.title', [], 'bn')])
        ->and(bellTitles($this->cashier))->toBe([])
        ->and(bellTitles($this->secretary))->toBe([])
        ->and(bellTitles($this->auditor))->toBe([])
        ->and(bellTitles($this->admin))->toBe([]);

    $data = $this->accountant->notifications()->sole()->data;

    expect($data['body'])->toContain('200.00')
        ->and($data['actions'][0]['url'])->toBe(PaymentResource::getUrl('view', ['record' => $payment], panel: 'admin'));
});

it('skips deactivated approvers', function (): void {
    $this->president->forceFill(['deactivated_at' => now()])->save();

    recordCash($this->cashier, $this->member);

    expect(bellTitles($this->president))->toBe([])
        ->and(bellTitles($this->accountant))->toHaveCount(1);
});

it('sends nothing when the action rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            recordCash($this->cashier, $this->member);

            throw new RuntimeException('something later failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table('notifications')->count())->toBe(0);
});

it('tells the cashier and the member when a payment is approved', function (): void {
    $memberUser = app(PortalAccounts::class)->forMember($this->member);
    $payment = recordCash($this->cashier, $this->member->fresh());

    app(ApprovePayment::class)($this->accountant, $payment);

    expect(bellTitles($this->cashier))->toBe([__('notifications.payment.approved.title')])
        ->and(bellTitles($memberUser))->toBe([__('notifications.member_payment.approved.title')])
        ->and(SmsMessage::query()->where('template_key', SmsTemplateKey::PaymentRejected->value)->exists())->toBeFalse();
});

it('tells the member why a payment was rejected, by SMS and in the portal', function (): void {
    $memberUser = app(PortalAccounts::class)->forMember($this->member);
    $payment = recordCash($this->cashier, $this->member->fresh());

    app(RejectPayment::class)($this->accountant, $payment, 'Cash was counted short');

    $sms = SmsMessage::query()->where('template_key', SmsTemplateKey::PaymentRejected->value)->sole();

    expect($sms->to)->toBe($this->member->mobile)
        ->and($sms->body)->toContain('Cash was counted short')
        ->and($sms->dedupe_key)->toBe("payment:{$payment->id}:rejected")
        ->and(bellTitles($memberUser))->toBe([__('notifications.member_payment.rejected.title')])
        ->and(bellTitles($this->cashier))->toBe([__('notifications.payment.rejected.title')]);
});

it('tells the recorder, the approver and the member when a payment is reversed', function (): void {
    $payment = app(ApprovePayment::class)($this->accountant, recordCash($this->cashier, $this->member));
    DB::table('notifications')->delete();

    app(ReversePayment::class)($this->president, $payment, 'Counted twice');

    expect(bellTitles($this->cashier))->toBe([__('notifications.payment.reversed.title')])
        ->and(bellTitles($this->accountant))->toBe([__('notifications.payment.reversed.title')])
        ->and(bellTitles($this->president))->toBe([])
        ->and(SmsMessage::query()->where('template_key', SmsTemplateKey::PaymentReversed->value)->sole()->body)->toContain('Counted twice');
});

it('asks for an expense approval and reports the outcome to the recorder', function (): void {
    receivePayment($this->member, '610');
    DB::table('notifications')->delete();
    $expense = app(RecordExpense::class)($this->cashier, ExpenseData::fromForm([
        'account_id' => account('5103')->id, 'paid_from' => 'cash', 'amount' => Money::ofTaka('100'),
        'spent_on' => '2026-07-05', 'description' => 'Tea for the meeting',
    ]));

    expect(bellTitles($this->accountant))->toBe([__('notifications.expense.submitted.title')])
        ->and(bellTitles($this->cashier))->toBe([]);

    app(ApproveExpense::class)($this->accountant, $expense);

    expect(bellTitles($this->cashier))->toBe([__('notifications.expense.approved.title')]);
});

it('asks someone other than the author to post a voucher, and tells the author once posted', function (): void {
    $draft = app(SaveJournalDraft::class)($this->accountant, null, JournalDraftData::fromForm([
        'voucher_type' => 'CV', 'entry_date' => '2026-07-05', 'narration' => 'Cash to bank',
        'lines' => [
            ['account_id' => account('1111')->id, 'debit' => Money::ofTaka('10'), 'credit' => null],
            ['account_id' => account('1101')->id, 'debit' => null, 'credit' => Money::ofTaka('10')],
        ],
    ]));

    expect(bellTitles($this->president))->toBe([__('notifications.journal_draft.submitted.title')])
        ->and(bellTitles($this->accountant))->toBe([]);

    app(PostJournalDraft::class)($this->president, $draft);

    expect(bellTitles($this->accountant))->toBe([__('notifications.journal_draft.posted.title')]);
});

it('tells the president when a staff account is given roles', function (): void {
    app(CreateStaffUser::class)($this->admin, new StaffUserData('Rina', 'rina@somiti.test', null, [Role::Accountant], 'en', 'a-long-password-1'));

    expect(bellTitles($this->president))->toBe([__('notifications.access.user_roles.title')])
        ->and(bellTitles($this->admin))->toBe([]);
});

it('reminds approvers once a day about items waiting too long', function (): void {
    $payment = recordCash($this->cashier, $this->member);
    DB::table('notifications')->delete();
    config(['somiti.approval_reminder_days' => 3]);
    $approvers = app(WorkflowNotifier::class)->whoCan('approve', $payment);

    expect(app(SendApprovalReminders::class)(CarbonImmutable::now()->addDays(2)))->toBe(0);

    $later = CarbonImmutable::now()->addDays(4);
    travelTo($later->toDateString());

    expect(app(SendApprovalReminders::class)($later))->toBe($approvers->count())
        ->and($approvers->pluck('id'))->toContain($this->accountant->id, $this->president->id)
        ->and(bellTitles($this->cashier))->toBe([])
        ->and(bellTitles($this->accountant))->toBe([__('notifications.reminder.waiting.title')]);

    app(SendApprovalReminders::class)($later);

    expect(bellTitles($this->accountant))->toHaveCount(1);

    config(['somiti.approval_reminder_days' => 0]);
    expect(app(SendApprovalReminders::class)($later->addDay()))->toBe(0);
});

it('sends a failed SMS again, but never a login code', function (): void {
    Queue::fake();
    $failed = SmsMessage::query()->create(['to' => '01712345678', 'body' => 'Hello', 'segments' => 1, 'status' => SmsStatus::Failed, 'error' => 'No balance']);
    $code = SmsMessage::query()->create(['to' => '01712345678', 'body' => '1234', 'segments' => 1, 'status' => SmsStatus::Failed, 'template_key' => SmsTemplateKey::LoginCode->value]);

    expect(fn () => app(ResendSms::class)($this->cashier, $failed))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ResendSms::class)($this->secretary, $code))->toThrow(AuthorizationException::class);

    $resent = app(ResendSms::class)($this->secretary, $failed);

    expect($resent->status)->toBe(SmsStatus::Queued)
        ->and($resent->error)->toBeNull();
    Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job): bool => $job->messageId === $failed->id);
});
