<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Integrity\InvariantChecker;
use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Members\Portal\MemberTokens;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Members\Registration\Actions\DecideRegistration;
use App\Domain\Members\Registration\Actions\InviteMember;
use App\Domain\Members\Registration\Actions\SaveRegistrationDraft;
use App\Domain\Members\Registration\Actions\SubmitRegistration;
use App\Domain\Members\Registration\Data\RegistrationDraft;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Enums\RegistrationDecisionType;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\Data\SmsResult;
use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Actions\DraftRatePlan;
use App\Domain\Settings\Actions\SubmitRatePlan;
use App\Domain\Settings\Data\RatePlanData;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature, invariant and concurrency tests boot the application against the
| PostgreSQL test database. Unit and arch tests run without the framework.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Invariants');

pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A user holding the given role (roles are seeded on demand).
 */
function userWithRole(Role $role): User
{
    test()->seed(RoleSeeder::class);

    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

/**
 * Inserts a minimal journal entry with one debit and one credit line straight into the
 * database. Only for tests that need "an account with postings" before PostJournal exists.
 */
function insertRawJournal(Account $debit, Account $credit, int $poisha = 10000): int
{
    $poster = User::factory()->create();
    $fiscalYear = app(OpenFiscalYear::class)(userWithRole(Role::Accountant), 2026);
    $period = $fiscalYear->periods()->firstOrFail();

    $entryId = (int) DB::table('journal_entries')->insertGetId([
        'fiscal_year_id' => $fiscalYear->id,
        'period_id' => $period->id,
        'voucher_type' => 'JV',
        'voucher_no' => 'JV-TEST-'.Str::random(6),
        'entry_date' => '2026-07-15',
        'narration' => 'test',
        'posted_by' => $poster->id,
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('journal_lines')->insert([
        ['journal_entry_id' => $entryId, 'line_no' => 1, 'account_id' => $debit->id, 'debit_poisha' => $poisha, 'credit_poisha' => 0],
        ['journal_entry_id' => $entryId, 'line_no' => 2, 'account_id' => $credit->id, 'debit_poisha' => 0, 'credit_poisha' => $poisha],
    ]);

    return $entryId;
}

function account(string $code): Account
{
    return Account::query()->where('code', $code)->sole();
}

/**
 * A two-line entry: debit one account, credit another.
 */
function simpleEntry(string $debitCode, string $creditCode, string $taka, string $date = '2026-07-15', VoucherType $type = VoucherType::Journal): JournalEntryData
{
    $amount = Money::ofTaka($taka);

    return new JournalEntryData(
        type: $type,
        entryDate: CarbonImmutable::parse($date, 'Asia/Dhaka'),
        narration: "Test {$debitCode}/{$creditCode}",
        lines: [
            JournalLineData::debit(account($debitCode), $amount),
            JournalLineData::credit(account($creditCode), $amount),
        ],
    );
}

/**
 * Rate plan data with sensible defaults (৳500/share, ৳10 service, ৳100 registration, due on the 10th).
 *
 * @param  array<string, mixed>  $overrides  form-style keys
 */
function ratePlanData(string $month, string $shareUnit = '500', array $overrides = []): RatePlanData
{
    return RatePlanData::fromForm([
        'effective_from' => $month,
        'share_unit_poisha' => Money::ofTaka($shareUnit),
        'service_charge_per_share_poisha' => Money::ofTaka('10'),
        'registration_fee_per_share_poisha' => Money::ofTaka('100'),
        'due_day' => 10,
        'grace_days' => 5,
        'late_fee_mode' => 'none',
        ...$overrides,
    ]);
}

/**
 * Drafts, submits and fully approves a plan (president + secretary), returning it fresh.
 */
function approvedPlan(string $month, string $shareUnit = '500', array $overrides = []): RatePlan
{
    $plan = app(DraftRatePlan::class)(userWithRole(Role::Accountant), ratePlanData($month, $shareUnit, $overrides));
    app(SubmitRatePlan::class)(User::query()->findOrFail($plan->created_by), $plan);
    app(ApproveRatePlan::class)(userWithRole(Role::President), $plan);
    app(ApproveRatePlan::class)(userWithRole(Role::Secretary), $plan);

    return $plan->fresh() ?? throw new RuntimeException('plan vanished');
}

/**
 * Id of a seeded nominee relation (father, mother, spouse, son, daughter, brother, sister, other).
 */
function relationId(string $key): int
{
    return (int) NomineeRelation::query()->where('key', $key)->value('id');
}

/**
 * A valid nominee form row; override any field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nominee(array $overrides = []): array
{
    return ['name' => 'Karima', 'relation_id' => relationId('spouse'), 'nid' => '1234567890', 'share_percent' => '100', ...$overrides];
}

/**
 * Member form data with defaults; pass overrides in form shape (e.g. 'mobile', 'nominees').
 *
 * @param  array<string, mixed>  $overrides
 */
function memberData(array $overrides = []): MemberData
{
    static $counter = 0;
    $counter++;

    return MemberData::fromForm([
        'name_bn' => 'রহিম উদ্দিন',
        'name_en' => 'Rahim Uddin',
        'mobile' => sprintf('0171%07d', $counter),
        'joined_on' => '2026-07-01',
        'nominees' => [nominee()],
        ...$overrides,
    ]);
}

/**
 * Onboards a member through CreateMember as the secretary.
 */
function onboard(int $shares = 1, string $from = '2026-07', array $overrides = []): Member
{
    return app(CreateMember::class)(
        userWithRole(Role::Secretary),
        memberData($overrides),
        $shares,
        YearMonth::parse($from),
    );
}

/**
 * Registration dues of a member as [month => amount in poisha], oldest first.
 *
 * @return array<string, int>
 */
function registrationDues(Member $member): array
{
    return Due::query()
        ->where('member_id', $member->id)
        ->where('type', DueType::Registration)
        ->orderBy('month')->orderBy('id')
        ->get()
        ->mapWithKeys(fn ($due): array => [(string) $due->month.'#'.$due->id => $due->amount_poisha->poisha])
        ->values()
        ->all();
}

/**
 * Moves the clock to a Dhaka date (used by the collections scenarios).
 */
function travelTo(string $date): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($date.' 10:00:00', 'Asia/Dhaka'));
}

/**
 * Cashier records, accountant approves; returns the approved payment.
 */
function receivePayment(Member $member, string $taka, string $method = 'cash', ?string $trx = null): Payment
{
    $payment = app(RecordPayment::class)(userWithRole(Role::Cashier), PaymentData::fromForm([
        'member_id' => $member->id,
        'method' => $method,
        'amount' => Money::ofTaka($taka),
        'received_on' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
        'trx_id' => $trx,
    ]));

    return app(ApprovePayment::class)(userWithRole(Role::Accountant), $payment);
}

/**
 * Generates the month on its 1st (advances are applied by the listener).
 */
function generateMonth(string $month): void
{
    travelTo($month.'-01');
    app(GenerateMonthlyDues::class)(YearMonth::parse($month));
}

/**
 * Deposit dues of a member as [month => [amount, paid]] in taka-less poisha.
 *
 * @return array<string, array{0: int, 1: int}>
 */
function depositDues(Member $member): array
{
    return Due::query()
        ->where('member_id', $member->id)
        ->where('type', DueType::Deposit)
        ->orderBy('month')
        ->get()
        ->mapWithKeys(fn ($due): array => [(string) $due->month => [$due->amount_poisha->poisha, $due->paid_poisha->poisha]])
        ->all();
}

function glBalance(string $code): int
{
    $account = Account::query()->where('code', $code)->sole();

    return (int) DB::table('journal_lines')->where('account_id', $account->id)->sum(DB::raw('credit_poisha - debit_poisha'));
}

function assertBooksTieOut(): void
{
    expect(app(InvariantChecker::class)->findings())->toBe([]);
}

/**
 * Runs $work($index) in $workers forked processes at the same time and returns what each
 * returned (or "error: …"). Children report through files and kill themselves so the
 * PHPUnit shutdown handlers never run twice.
 *
 * @param  Closure(int): string  $work
 * @return array<int, string>
 */
function inParallel(int $workers, Closure $work): array
{
    $directory = sys_get_temp_dir().'/somiti-concurrency-'.bin2hex(random_bytes(4));
    mkdir($directory);

    DB::disconnect();
    $children = [];

    for ($index = 0; $index < $workers; $index++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Could not fork.');
        }

        if ($pid === 0) {
            try {
                DB::reconnect();
                $result = $work($index);
            } catch (Throwable $exception) {
                $result = 'error: '.$exception::class.': '.$exception->getMessage();
            }

            file_put_contents("{$directory}/{$index}", $result);
            posix_kill(posix_getpid(), SIGKILL);
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    DB::reconnect();

    $results = [];

    for ($index = 0; $index < $workers; $index++) {
        $results[$index] = (string) @file_get_contents("{$directory}/{$index}");
        @unlink("{$directory}/{$index}");
    }

    rmdir($directory);

    return $results;
}

/**
 * Collects what would be sent instead of sending it.
 */
function fakeSms(bool $ok = true): object
{
    $gateway = new class($ok) implements SmsGateway
    {
        /** @var list<array{0: string, 1: string}> */
        public array $sent = [];

        public function __construct(private readonly bool $ok) {}

        public function name(): string
        {
            return 'fake';
        }

        public function send(string $to, string $body): SmsResult
        {
            $this->sent[] = [$to, $body];

            return $this->ok ? SmsResult::sent('fake-'.count($this->sent)) : SmsResult::failed('provider down');
        }
    };

    app()->instance(SmsGateway::class, $gateway);

    return $gateway;
}

/**
 * A fresh access token for the member's portal account.
 */
function memberToken(Member $member): string
{
    $user = app(PortalAccounts::class)->forMember($member);

    return app(MemberTokens::class)->issue($user)['access_token'];
}

/**
 * The translation key of the DomainRuleViolation the callback throws, or null when it passes.
 */
function memberRuleKey(Closure $callback): ?string
{
    try {
        $callback();
    } catch (DomainRuleViolation $violation) {
        return $violation->translationKey;
    }

    return null;
}

/**
 * The secretary invites a new member (mobile + password); returns the open registration.
 */
function invite(string $mobile = '01811111111', string $password = 'secret-123'): MemberApplication
{
    return app(InviteMember::class)(userWithRole(Role::Secretary), $mobile, $password);
}

/**
 * A complete registration as the member would type it; override any field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationInput(array $overrides = []): array
{
    return [
        'name_bn' => 'করিম মিয়া',
        'name_en' => 'Karim Mia',
        'guardian_name' => 'Abdul Mia',
        'nid' => '9876543210',
        'date_of_birth' => '1990-01-15',
        'address' => 'Mirpur, Dhaka',
        'requested_shares' => 2,
        'nominees' => [nominee()],
        ...$overrides,
    ];
}

/**
 * Saves a complete draft for the applicant.
 *
 * @param  array<string, mixed>  $overrides
 */
function completeRegistration(MemberApplication $application, array $overrides = []): MemberApplication
{
    return app(SaveRegistrationDraft::class)($application, RegistrationDraft::fromInput(registrationInput($overrides)));
}

/**
 * An invited, completed and submitted registration (waiting for the first approver).
 */
function submittedRegistration(string $mobile = '01811111111'): MemberApplication
{
    return app(SubmitRegistration::class)(completeRegistration(invite($mobile)), (string) Str::uuid());
}

/**
 * Approves every remaining step of the chain, each by a fresh user holding that role.
 */
function approveRegistration(MemberApplication $application, string $from = '2026-07', ?int $shares = null): MemberApplication
{
    while ($application->status === MemberApplicationStatus::Submitted) {
        $role = $application->currentRole() ?? throw new RuntimeException('no current step');
        $application = app(DecideRegistration::class)(
            userWithRole($role),
            $application,
            RegistrationDecisionType::Approve,
            null,
            $shares,
            YearMonth::parse($from),
        );
    }

    return $application;
}
