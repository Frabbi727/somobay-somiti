<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Actions\OpenFiscalYear;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Data\JournalEntryData;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Enums\VoucherType;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Contributions\Enums\PaymentMethod;
use App\Domain\Investments\Actions\ApproveInvestment;
use App\Domain\Investments\Actions\RecordInvestment;
use App\Domain\Investments\Actions\RecordInvestmentIncome;
use App\Domain\Investments\Data\InvestmentData;
use App\Domain\Investments\Enums\InvestmentType;
use App\Domain\Members\Enums\MemberStatus;
use App\Domain\Members\Models\Member;
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Three months of realistic postings for fiscal year 2026-27, for demos and report checks.
 * Run with: php artisan db:seed --class=DemoBooksSeeder
 */
final class DemoBooksSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RoleSeeder::class, ChartOfAccountsSeeder::class]);

        $accountant = User::query()->firstOrCreate(
            ['email' => 'accountant@somiti.test'],
            ['name' => 'Demo Accountant', 'password' => 'password'],
        );
        $accountant->assignRole(Role::Accountant->value);

        if (FiscalYear::query()->where('start_year', 2026)->doesntExist()) {
            app(OpenFiscalYear::class)($accountant, 2026);
        }

        $post = app(PostJournal::class);
        $shareCounts = [1 => 2, 2 => 1, 3 => 3];
        $members = [];

        foreach (array_keys($shareCounts) as $number) {
            $members[$number] = Member::query()->firstOrCreate(['mobile' => sprintf('0170000000%d', $number)], [
                'member_no' => sprintf('M-%04d', (int) DB::scalar("SELECT nextval('member_no_seq')")),
                'name_bn' => "ডেমো সদস্য {$number}",
                'name_en' => "Demo Member {$number}",
                'status' => MemberStatus::Active,
                'joined_on' => '2026-07-01',
                'created_by' => $accountant->id,
            ]);
        }

        foreach (['2026-07', '2026-08', '2026-09'] as $index => $month) {
            foreach ($shareCounts as $member => $shares) {
                $deposit = Money::ofTaka('500')->multipliedByInt($shares);
                $service = Money::ofTaka('10')->multipliedByInt($shares);
                $lines = [
                    JournalLineData::debit($this->account($member === 2 ? '1121' : '1101'), $deposit->plus($service)),
                    JournalLineData::credit($this->account('2101'), $deposit, $members[$member]->id, 'Monthly deposit'),
                    JournalLineData::credit($this->account('4111'), $service),
                ];

                if ($index === 0) {
                    $fee = Money::ofTaka('100')->multipliedByInt($shares);
                    $lines[0] = JournalLineData::debit($this->account($member === 2 ? '1121' : '1101'), $deposit->plus($service)->plus($fee));
                    $lines[] = JournalLineData::credit($this->account('4101'), $fee);
                }

                $post($accountant, new JournalEntryData(
                    VoucherType::Receipt,
                    CarbonImmutable::parse("{$month}-1".(5 + $member)),
                    "Collection from member {$member} for {$month}",
                    $lines,
                ));
            }

            $post($accountant, new JournalEntryData(VoucherType::Contra, CarbonImmutable::parse("{$month}-20"), 'Cash deposited to bank', [
                JournalLineData::debit($this->account('1111'), Money::ofTaka('2000')),
                JournalLineData::credit($this->account('1101'), Money::ofTaka('2000')),
            ]));

            $post($accountant, new JournalEntryData(VoucherType::Payment, CarbonImmutable::parse("{$month}-25"), 'Stationery and SMS', [
                JournalLineData::debit($this->account('5101'), Money::ofTaka('120.50')),
                JournalLineData::debit($this->account('5105'), Money::ofTaka('35.75')),
                JournalLineData::credit($this->account('1101'), Money::ofTaka('156.25')),
            ]));
        }

        // Investments go through the register (Phase 10), which keeps 1301 tied to it.
        $president = User::query()->firstOrCreate(
            ['email' => 'president@somiti.test'],
            ['name' => 'Demo President', 'password' => 'password'],
        );
        $president->assignRole(Role::President->value);

        $fixedDeposit = app(RecordInvestment::class)($accountant, InvestmentData::fromForm([
            'type' => InvestmentType::FixedDeposit,
            'institution' => 'Sonali Bank, Local Office',
            'instrument_no' => 'FDR-2026-0815',
            'principal' => Money::ofTaka('3000'),
            'funded_from' => PaymentMethod::Bank,
            'invested_on' => '2026-08-28',
            'matures_on' => '2027-08-28',
            'expected_rate' => '8.25',
            'idempotency_key' => '00000000-0000-4000-8000-000000000301',
        ]));
        app(ApproveInvestment::class)($president, $fixedDeposit);

        app(RecordInvestmentIncome::class)($accountant, $fixedDeposit, Money::ofTaka('62.40'), Money::zero(), CarbonImmutable::parse('2026-09-30'), PaymentMethod::Bank, 'FDR profit');

        $mistake = $post($accountant, new JournalEntryData(VoucherType::Payment, CarbonImmutable::parse('2026-09-26'), 'Rent paid twice by mistake', [
            JournalLineData::debit($this->account('5103'), Money::ofTaka('800')),
            JournalLineData::credit($this->account('1101'), Money::ofTaka('800')),
        ]));

        app(ReverseJournal::class)($accountant, $mistake, 'Duplicate rent payment', CarbonImmutable::parse('2026-09-27'));
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->sole();
    }
}
