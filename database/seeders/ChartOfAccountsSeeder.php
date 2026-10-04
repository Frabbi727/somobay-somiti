<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Models\Account;
use Illuminate\Database\Seeder;

/**
 * The standard somiti chart of accounts (SOMITI_SPEC.md P1.S1). Safe to run repeatedly.
 */
final class ChartOfAccountsSeeder extends Seeder
{
    /**
     * code => [english name, bangla name, type, is_control, requires_member]
     *
     * @var array<int, array{0: string, 1: string, 2: AccountType, 3: bool, 4: bool}>
     */
    public const array ACCOUNTS = [
        '1101' => ['Cash in Hand', 'হাতে নগদ', AccountType::Asset, false, false],
        '1111' => ['Bank Account', 'ব্যাংক হিসাব', AccountType::Asset, false, false],
        '1121' => ['bKash Wallet', 'বিকাশ ওয়ালেট', AccountType::Asset, false, false],
        '1122' => ['Nagad Wallet', 'নগদ ওয়ালেট', AccountType::Asset, false, false],
        '1201' => ['Member Dues Receivable', 'সদস্যদের বকেয়া পাওনা', AccountType::Asset, true, true],
        '1301' => ['Investments – Fixed Deposits', 'বিনিয়োগ – স্থায়ী আমানত (এফডিআর)', AccountType::Asset, true, false],
        '1302' => ['Investments – Savings Certificates', 'বিনিয়োগ – সঞ্চয়পত্র', AccountType::Asset, true, false],
        '1303' => ['Investments – Government Securities', 'বিনিয়োগ – সরকারি সিকিউরিটিজ', AccountType::Asset, true, false],
        '1304' => ['Investments – Shares & Company Securities', 'বিনিয়োগ – শেয়ার ও কোম্পানি সিকিউরিটিজ', AccountType::Asset, true, false],
        '1305' => ['Investments – Other Cooperatives', 'বিনিয়োগ – অন্যান্য সমবায়', AccountType::Asset, true, false],
        '1399' => ['Investments – Other', 'বিনিয়োগ – অন্যান্য', AccountType::Asset, true, false],
        '2101' => ['Member Savings Deposits', 'সদস্য সঞ্চয় আমানত', AccountType::Liability, true, true],
        '2111' => ['Member Advance', 'সদস্য অগ্রিম জমা', AccountType::Liability, true, true],
        '2201' => ['Dividend Payable', 'প্রদেয় লভ্যাংশ', AccountType::Liability, true, true],
        '2211' => ['Cooperative Development Fund Payable', 'সমবায় উন্নয়ন তহবিল (প্রদেয়)', AccountType::Liability, false, false],
        '2301' => ['Exit Settlements Payable', 'সদস্য প্রত্যাহার নিষ্পত্তি (প্রদেয়)', AccountType::Liability, true, true],
        '3101' => ['Share Capital', 'শেয়ার মূলধন', AccountType::Equity, false, false],
        '3201' => ['Reserve Fund', 'সংরক্ষিত তহবিল', AccountType::Equity, false, false],
        '3901' => ['Accumulated Surplus', 'সঞ্চিত উদ্বৃত্ত', AccountType::Equity, false, false],
        '4101' => ['Registration Fee Income', 'ভর্তি ফি আয়', AccountType::Income, false, false],
        '4111' => ['Service Charge Income', 'সার্ভিস চার্জ আয়', AccountType::Income, false, false],
        '4121' => ['Late Fee Income', 'বিলম্ব ফি আয়', AccountType::Income, false, false],
        '4201' => ['Investment Profit', 'বিনিয়োগ মুনাফা', AccountType::Income, false, false],
        '5101' => ['Office & Stationery', 'অফিস ও স্টেশনারি খরচ', AccountType::Expense, false, false],
        '5102' => ['Honorarium & Salaries', 'সম্মানী ও বেতন', AccountType::Expense, false, false],
        '5103' => ['Rent & Utilities', 'ভাড়া ও ইউটিলিটি', AccountType::Expense, false, false],
        '5104' => ['Bank & Wallet Charges', 'ব্যাংক ও ওয়ালেট চার্জ', AccountType::Expense, false, false],
        '5105' => ['SMS & Communication', 'এসএমএস ও যোগাযোগ', AccountType::Expense, false, false],
        '5106' => ['Tax & Duty Deducted at Source', 'উৎসে কর্তিত কর ও শুল্ক', AccountType::Expense, false, false],
        '5201' => ['Impairment Loss on Investments', 'বিনিয়োগে অবচয় ক্ষতি', AccountType::Expense, false, false],
        '5199' => ['Other Expenses', 'অন্যান্য খরচ', AccountType::Expense, false, false],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $code => [$nameEn, $nameBn, $type, $isControl, $requiresMember]) {
            Account::withTrashed()->firstOrCreate(['code' => (string) $code], [
                'name_en' => $nameEn,
                'name_bn' => $nameBn,
                'type' => $type,
                'normal_balance' => $type->normalBalance(),
                'is_control' => $isControl,
                'requires_member' => $requiresMember,
                'is_active' => true,
            ]);
        }
    }
}
