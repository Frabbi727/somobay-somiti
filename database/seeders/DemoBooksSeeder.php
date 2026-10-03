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
use App\Enums\Role;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

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
        $members = [1 => 2, 2 => 1, 3 => 3];

        foreach (['2026-07', '2026-08', '2026-09'] as $index => $month) {
            foreach ($members as $member => $shares) {
                $deposit = Money::ofTaka('500')->multipliedByInt($shares);
                $service = Money::ofTaka('10')->multipliedByInt($shares);
                $lines = [
                    JournalLineData::debit($this->account($member === 2 ? '1121' : '1101'), $deposit->plus($service)),
                    JournalLineData::credit($this->account('2101'), $deposit, $member, 'Monthly deposit'),
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

        $post($accountant, new JournalEntryData(VoucherType::Payment, CarbonImmutable::parse('2026-08-28'), 'Investment in fixed deposit', [
            JournalLineData::debit($this->account('1301'), Money::ofTaka('3000')),
            JournalLineData::credit($this->account('1111'), Money::ofTaka('3000')),
        ]));

        $post($accountant, new JournalEntryData(VoucherType::Receipt, CarbonImmutable::parse('2026-09-30'), 'Fixed deposit profit', [
            JournalLineData::debit($this->account('1111'), Money::ofTaka('62.40')),
            JournalLineData::credit($this->account('4201'), Money::ofTaka('62.40')),
        ]));

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
