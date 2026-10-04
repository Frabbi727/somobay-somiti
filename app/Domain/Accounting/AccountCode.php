<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * Codes of the accounts the application posts to by itself (SOMITI_SPEC.md §5.5).
 */
final class AccountCode
{
    public const string CASH = '1101';

    public const string BANK = '1111';

    public const string BKASH = '1121';

    public const string NAGAD = '1122';

    public const string DUES_RECEIVABLE = '1201';

    public const string MEMBER_SAVINGS = '2101';

    public const string MEMBER_ADVANCE = '2111';

    public const string DIVIDEND_PAYABLE = '2201';

    public const string EXIT_PAYABLE = '2301';

    public const string REGISTRATION_FEE_INCOME = '4101';

    public const string SERVICE_CHARGE_INCOME = '4111';

    public const string LATE_FEE_INCOME = '4121';

    /** Bank & wallet charges (transfer and cash-out fees). */
    public const string BANK_CHARGES = '5104';
}
