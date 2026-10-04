<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;

/**
 * Who sees which part of the staff panel (menus, lists, records, reports). The one place to change
 * it. What each role may *do* inside an area is still decided by the policies (maker-checker etc.);
 * the auditor and the super admin see everything read-only.
 *
 * Follows the roles in SOMITI_SPEC.md §1.3.
 */
enum Area: string
{
    /** Member records — everyone on the staff needs to look members up. */
    case Members = 'members';

    /** Payments, dues, advances, expenses and fund transfers (the cash desk). */
    case Collections = 'collections';

    /** Vouchers, chart of accounts, fiscal years, reconciliation, investments, year-end, integrity. */
    case Accounting = 'accounting';

    /** Meetings, resolutions and member exits. */
    case Governance = 'governance';

    /** Rate plans (prices are set by the committee, drafted by the accountant or secretary). */
    case RatePlans = 'rate_plans';

    /** SMS templates and the SMS log. */
    case Messaging = 'messaging';

    /** Trial balance, ledgers, financial statements, investment register. */
    case FinancialReports = 'financial_reports';

    /** Member statement, share register, dividend register. */
    case MemberReports = 'member_reports';

    /** Defaulters, collection summary and the cash book — what the cash desk works from. */
    case CashReports = 'cash_reports';

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        $oversight = [Role::SuperAdmin, Role::Auditor, Role::President];

        return match ($this) {
            self::Members => [...$oversight, Role::Secretary, Role::Cashier, Role::Accountant],
            self::Collections => [...$oversight, Role::Cashier, Role::Accountant],
            self::Accounting => [...$oversight, Role::Accountant],
            self::Governance => [...$oversight, Role::Secretary, Role::Accountant],
            self::RatePlans => [...$oversight, Role::Secretary, Role::Accountant],
            self::Messaging => [Role::SuperAdmin, Role::Auditor, Role::Secretary],
            self::FinancialReports => [...$oversight, Role::Accountant],
            self::MemberReports => [...$oversight, Role::Secretary, Role::Accountant],
            self::CashReports => [...$oversight, Role::Cashier, Role::Accountant, Role::Secretary],
        };
    }

    public function allows(?User $user): bool
    {
        return $user !== null && $user->hasAnyOf(...$this->roles());
    }
}
