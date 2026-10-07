<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Actions\ApproveExpense;
use App\Domain\Accounting\Actions\ApproveFundTransfer;
use App\Domain\Accounting\Actions\LockPeriod;
use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\RecordExpense;
use App\Domain\Accounting\Actions\RecordFundTransfer;
use App\Domain\Accounting\Data\ExpenseData;
use App\Domain\Accounting\Data\FundTransferData;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Contributions\Actions\ApplyLateFees;
use App\Domain\Contributions\Actions\ApprovePayment;
use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Contributions\Actions\RecordPayment;
use App\Domain\Contributions\Data\PaymentData;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Contributions\Models\Due;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Governance\Actions\CreateMeeting;
use App\Domain\Governance\Actions\DecideResolution;
use App\Domain\Governance\Actions\HoldMeeting;
use App\Domain\Governance\Actions\ProposeResolution;
use App\Domain\Governance\Actions\RecordAttendance;
use App\Domain\Governance\Data\MeetingData;
use App\Domain\Governance\Data\ResolutionData;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Integrity\Actions\RunIntegrityChecks;
use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\RecordInvestment;
use App\Domain\Investments\Actions\RecordInvestmentIncome;
use App\Domain\Investments\Data\InvestmentData;
use App\Domain\Investments\Models\Investment;
use App\Domain\Members\Actions\CreateMember;
use App\Domain\Members\Data\MemberData;
use App\Domain\Members\Models\Member;
use App\Domain\Members\Models\NomineeRelation;
use App\Domain\Members\Portal\PortalAccounts;
use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Actions\DraftRatePlan;
use App\Domain\Settings\Actions\LinkRatePlanResolution;
use App\Domain\Settings\Actions\SubmitRatePlan;
use App\Domain\Settings\Data\RatePlanData;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A realistic somiti for trying every screen locally (`php artisan somiti:demo`). Everything goes
 * through the real Actions, so the books, registers and integrity checks agree.
 *
 * The society starts in July 2025, so fiscal year 2025-26 is complete (July–May locked, June open)
 * and the year-end, dividends and member exits can be tried today. Some work is left pending on
 * purpose so each approval step can be tried by the right role.
 *
 * Every staff login uses the password "password"; members log in to /portal with their mobile and
 * "password" (or an SMS code, written to storage/logs/laravel.log).
 */
final class LocalDemoSeeder extends Seeder
{
    public const string PASSWORD = StaffUserSeeder::PASSWORD;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Member> */
    private array $members = [];

    public function run(): void
    {
        if (Member::query()->exists()) {
            throw new RuntimeException('The database already has members. Run `php artisan somiti:demo --fresh` to rebuild it.');
        }

        $this->call([RoleSeeder::class, ChartOfAccountsSeeder::class, SmsTemplateSeeder::class]);
        $this->createStaff();

        $today = CarbonImmutable::now('Asia/Dhaka');

        try {
            $this->foundSociety();

            foreach (YearMonth::range(YearMonth::of(2025, 7), YearMonth::fromDate($today)) as $month) {
                $this->runMonth($month, $today);
            }

            $this->at($today);
            $this->leaveWorkPending();
            $this->lockFiscalYear2025();
            app(RunIntegrityChecks::class)();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function createStaff(): void
    {
        $this->call(StaffUserSeeder::class);

        foreach (array_keys(StaffUserSeeder::USERS) as $email) {
            $this->users[$email] = User::query()->where('email', $email)->firstOrFail();
        }
    }

    private function user(string $who): User
    {
        return $this->users[$who.'@somiti.test'];
    }

    private function at(CarbonImmutable|string $moment): void
    {
        CarbonImmutable::setTestNow(is_string($moment) ? CarbonImmutable::parse($moment, 'Asia/Dhaka') : $moment);
    }

    /**
     * July 2025: the fiscal year, a committee meeting ratifying the advance policy, the first rate
     * plan and the founding members.
     */
    private function foundSociety(): void
    {
        $this->at('2025-06-25 11:00');
        app(OpenFiscalYear::class)($this->user('accountant'), 2025);

        $this->committeeMeeting('2025-06-25 11:00', 'Founding committee meeting', [
            ['advance_policy', 'Ratify the advance policy', 'Advance payments are held in 2111 and applied at the rate of each month (apply_at_current_rate).'],
        ]);

        $this->ratePlan('2025-07', '500');

        $this->at('2025-07-01 10:00');

        foreach ([
            ['রহিম উদ্দিন', 'Rahim Uddin', '01711000001', 2, [['রাবেয়া খাতুন', 'Wife', '100']]],
            ['করিম মিয়া', 'Karim Mia', '01711000002', 1, [['ফাতেমা বেগম', 'Mother', '100']]],
            ['সালমা বেগম', 'Salma Begum', '01711000003', 3, [['আরিফ হোসেন', 'Son', '100']]],
            ['জামাল হোসেন', 'Jamal Hossain', '01711000004', 1, [['রোকেয়া হোসেন', 'Wife', '100']]],
            ['ফারুক আহমেদ', 'Faruk Ahmed', '01711000006', 1, [['নাজমা আহমেদ', 'Wife', '100']]],
        ] as [$nameBn, $nameEn, $mobile, $shares, $nominees]) {
            $this->join($nameBn, $nameEn, $mobile, $shares, $nominees, YearMonth::of(2025, 7));
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $nominees
     */
    private function join(string $nameBn, string $nameEn, string $mobile, int $shares, array $nominees, YearMonth $from): void
    {
        $member = app(CreateMember::class)($this->user('secretary'), MemberData::fromForm([
            'name_bn' => $nameBn,
            'name_en' => $nameEn,
            'mobile' => $mobile,
            'joined_on' => $from->toDateString(),
            'address' => 'Mirpur, Dhaka',
            'nominees' => array_map(fn (array $nominee): array => [
                'name' => $nominee[0],
                'relation_id' => NomineeRelation::query()->where('key', match ($nominee[1]) {
                    'Wife', 'Husband' => 'spouse',
                    default => strtolower($nominee[1]),
                })->value('id'),
                'nid' => '19900000000'.substr($mobile, -2),
                'share_percent' => $nominee[2],
            ], $nominees),
        ]), $shares, $from);

        $portal = app(PortalAccounts::class)->forMember($member);
        $portal->forceFill(['password' => self::PASSWORD])->save();

        $this->members[$nameEn] = $member;
    }

    private function runMonth(YearMonth $month, CarbonImmutable $today): void
    {
        if ($month->equals(YearMonth::of(2026, 1))) {
            $this->at('2026-01-01 00:10');
            $this->join('নাসরিন আক্তার', 'Nasrin Akter', '01711000005', 2, [['শাকিল আহমেদ', 'Son', '60'], ['সুমি আক্তার', 'Daughter', '40']], $month);
        }

        if ($month->equals(YearMonth::of(2026, 7))) {
            $this->at('2026-06-28 10:00');
            app(OpenFiscalYear::class)($this->user('accountant'), 2026);
        }

        $this->at($month->day(1)->setTime(0, 30));
        app(GenerateMonthlyDues::class)($month);

        $collectionDay = $month->day(3)->setTime(11, 0);

        if ($collectionDay->isAfter($today)) {
            return;
        }

        $this->at($collectionDay);
        $this->collect($month);

        if ($month->day(20)->isBefore($today)) {
            $this->at($month->day(20)->setTime(1, 0));
            app(ApplyLateFees::class)();
            $this->monthlyBooks($month);
        }

        if ($month->equals(YearMonth::of(2025, 12))) {
            $this->raiseRateFromJanuary();
        }
    }

    /**
     * Cashier records, accountant approves: each member pays what is due, except —
     * Salma prepaid a large advance in July 2025, Karim pays by bKash, and Jamal stops paying
     * after July 2026 (a defaulter with late fees). October 2026 is left for you to approve.
     */
    private function collect(YearMonth $month): void
    {
        foreach ($this->members as $name => $member) {
            if ($month->isAfter(YearMonth::of(2026, 9))) {
                continue; // October: see leaveWorkPending()
            }

            if ($name === 'Jamal Hossain' && $month->isAfter(YearMonth::of(2026, 7))) {
                continue;
            }

            $amount = $name === 'Salma Begum' && $month->equals(YearMonth::of(2025, 7))
                ? Money::ofTaka('12000')
                : $this->outstanding($member, $month);

            if ($amount->isPositive()) {
                $this->pay($member, $amount, $name === 'Karim Mia' ? PaymentMethod::Bkash : PaymentMethod::Cash, approve: true);
            }
        }
    }

    private function outstanding(Member $member, YearMonth $upTo): Money
    {
        return Money::ofPoisha((int) Due::query()
            ->where('member_id', $member->id)
            ->where('status', DueStatus::Open)
            ->where('month', '<=', $upTo->toDateString())
            ->sum('outstanding_poisha'));
    }

    private function pay(Member $member, Money $amount, PaymentMethod $method, bool $approve): Payment
    {
        $payment = app(RecordPayment::class)($this->user('cashier'), PaymentData::fromForm([
            'member_id' => $member->id,
            'method' => $method,
            'amount' => $amount,
            'received_on' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'trx_id' => $method === PaymentMethod::Cash ? null : 'BK'.strtoupper(Str::random(8)),
        ]));

        return $approve ? app(ApprovePayment::class)($this->user('accountant'), $payment) : $payment;
    }

    /**
     * Stationery and a bank deposit each quarter, an FDR in January and its half-yearly profit in June.
     */
    private function monthlyBooks(YearMonth $month): void
    {
        if (in_array($month->month, [9, 12, 3, 6], true)) {
            $this->expense('5101', '120', 'Receipt books and registers', $month->day(20)->toDateString(), approve: true);

            $cash = Money::ofPoisha((int) DB::table('journal_lines')
                ->where('account_id', Account::query()->where('code', '1101')->value('id'))
                ->sum(DB::raw('debit_poisha - credit_poisha')));
            $deposit = $cash->minus(Money::ofTaka('3000'));

            if ($deposit->isPositive()) {
                $this->transfer($deposit, $month->day(20)->toDateString(), approve: true);
            }
        }

        if ($month->equals(YearMonth::of(2026, 1))) {
            $fdr = app(RecordInvestment::class)($this->user('accountant'), InvestmentData::fromForm([
                'type' => 'fixed_deposit', 'institution' => 'Sonali Bank, Mirpur Branch', 'instrument_no' => 'FDR-2026-0117',
                'principal' => Money::ofTaka('20000'), 'funded_from' => 'bank', 'invested_on' => $month->day(20)->toDateString(),
                'matures_on' => '2027-01-20', 'expected_rate' => '8.5',
            ]));
            app(ApproveInvestment::class)($this->user('president'), $fdr);
        }

        if ($month->equals(YearMonth::of(2026, 6))) {
            $fdr = Investment::query()->where('instrument_no', 'FDR-2026-0117')->firstOrFail();
            app(RecordInvestmentIncome::class)($this->user('accountant'), $fdr, Money::ofTaka('850'), Money::ofTaka('85'), $month->day(20), PaymentMethod::Bank, 'Half-yearly profit');
        }
    }

    private function expense(string $code, string $taka, string $purpose, string $date, bool $approve): void
    {
        $expense = app(RecordExpense::class)($this->user('cashier'), ExpenseData::fromForm([
            'account_id' => Account::query()->where('code', $code)->value('id'),
            'paid_from' => 'cash',
            'amount' => Money::ofTaka($taka),
            'spent_on' => $date,
            'payee' => 'Mirpur Stationers',
            'description' => $purpose,
        ]));

        if ($approve) {
            app(ApproveExpense::class)($this->user('accountant'), $expense);
        }
    }

    private function transfer(Money $amount, string $date, bool $approve): void
    {
        $transfer = app(RecordFundTransfer::class)($this->user('cashier'), FundTransferData::fromForm([
            'from_method' => 'cash', 'to_method' => 'bank', 'amount' => $amount, 'charge' => Money::zero(),
            'transferred_on' => $date, 'reference' => 'DEP-'.str_replace('-', '', $date),
        ]));

        if ($approve) {
            app(ApproveFundTransfer::class)($this->user('accountant'), $transfer);
        }
    }

    /**
     * The share unit rises to ৳600 from January 2026, adopted by a committee resolution.
     */
    private function raiseRateFromJanuary(): void
    {
        $resolution = $this->committeeMeeting('2025-12-26 18:00', 'December committee meeting', [
            ['rate_plan', 'Share unit ৳600 from January 2026', 'The monthly deposit per share becomes ৳600 from January 2026; service charge and registration fee stay the same.'],
        ]);

        $this->ratePlan('2026-01', '600', $resolution);
    }

    private function ratePlan(string $from, string $shareUnit, ?Resolution $resolution = null): void
    {
        $plan = app(DraftRatePlan::class)($this->user('accountant'), RatePlanData::fromForm([
            'effective_from' => $from,
            'share_unit_poisha' => Money::ofTaka($shareUnit),
            'service_charge_per_share_poisha' => Money::ofTaka('20'),
            'registration_fee_per_share_poisha' => Money::ofTaka('100'),
            'due_day' => 10,
            'grace_days' => 5,
            'late_fee_mode' => 'fixed',
            'late_fee_fixed_poisha' => Money::ofTaka('20'),
            'late_fee_frequency' => 'once',
        ]));

        if ($resolution !== null) {
            app(LinkRatePlanResolution::class)($this->user('accountant'), $plan, $resolution);
        }

        app(SubmitRatePlan::class)($this->user('accountant'), $plan);
        app(ApproveRatePlan::class)($this->user('president'), $plan);
        app(ApproveRatePlan::class)($this->user('secretary'), $plan);
    }

    /**
     * A committee meeting attended by the whole committee, passing each resolution unanimously.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $resolutions  [subject, title, text]
     */
    private function committeeMeeting(string $at, string $title, array $resolutions): Resolution
    {
        $this->at($at);
        $secretary = $this->user('secretary');
        $meeting = app(CreateMeeting::class)($secretary, MeetingData::fromForm([
            'type' => 'committee', 'title' => $title, 'scheduled_at' => $at, 'venue' => 'Somiti office',
        ]));

        $proposed = array_map(fn (array $item): Resolution => app(ProposeResolution::class)($secretary, $meeting, ResolutionData::fromForm([
            'subject' => $item[0], 'title' => $item[1], 'body' => $item[2],
        ])), $resolutions);

        $committee = array_map(fn (string $who): int => $this->user($who)->id, ['president', 'secretary', 'cashier', 'accountant', 'accountant2']);
        app(RecordAttendance::class)($secretary, $meeting, $committee);
        app(HoldMeeting::class)($secretary, $meeting, 'All committee members present.');

        $last = null;

        foreach ($proposed as $resolution) {
            $last = app(DecideResolution::class)($secretary, $resolution, count($committee), 0, 0);
        }

        return $last ?? throw new RuntimeException('No resolution.');
    }

    /**
     * Today: work waiting for each role to try.
     */
    private function leaveWorkPending(): void
    {
        $october = YearMonth::current();

        foreach (['Salma Begum', 'Faruk Ahmed', 'Nasrin Akter'] as $name) {
            $amount = $this->outstanding($this->members[$name], $october);

            if ($amount->isPositive()) {
                $this->pay($this->members[$name], $amount, PaymentMethod::Cash, approve: true);
            }
        }

        // For the accountant to approve (the cashier recorded them).
        $this->pay($this->members['Rahim Uddin'], $this->outstanding($this->members['Rahim Uddin'], $october), PaymentMethod::Cash, approve: false);
        $this->pay($this->members['Karim Mia'], $this->outstanding($this->members['Karim Mia'], $october), PaymentMethod::Bkash, approve: false);

        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $this->expense('5103', '1500', 'October office rent', $today, approve: false);
        $this->transfer(Money::ofTaka('2000'), $today, approve: false);

        // The AGM, today at 6 pm: record attendance, hold it and decide the year-end resolution.
        $agm = app(CreateMeeting::class)($this->user('secretary'), MeetingData::fromForm([
            'type' => 'general', 'title' => 'Annual general meeting 2025-26', 'scheduled_at' => $today.' 18:00', 'venue' => 'Community centre, Mirpur',
            'agenda' => "1. Accounts for 2025-26\n2. Appropriation of profit and dividend\n3. Any other business",
        ]));
        app(ProposeResolution::class)($this->user('secretary'), $agm, ResolutionData::fromForm([
            'subject' => 'year_end', 'title' => 'Approve the 2025-26 accounts and dividend',
            'body' => 'The accounts for 2025-26 are approved; the profit is appropriated as required by s.34 and the rest paid as dividend by share-months.',
        ]));
    }

    /**
     * Month-end locks for 2025-26 (July–May), leaving June open for the year-end.
     */
    private function lockFiscalYear2025(): void
    {
        $year = FiscalYear::query()->where('start_year', 2025)->firstOrFail();

        foreach ($year->periods()->where('sequence', '<', 12)->orderBy('sequence')->get() as $period) {
            app(LockPeriod::class)($this->user('accountant'), $period);
        }
    }
}
