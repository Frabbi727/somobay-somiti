<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a staff role may see or do. The president decides which role holds which permission
 * (Settings › Role permissions); defaultRoles() is the starting point from SOMITI_SPEC.md §1.3.
 *
 * Some rules are never a tick-box and stay in code: maker-checker (nobody approves their own entry),
 * the signatures a rate plan and a year-end need, the president's approval above the large-amount
 * thresholds, user management (super admin), these permissions themselves (president), and the
 * auditor staying read-only (isWrite() permissions can never be given to the auditor).
 */
enum Permission: string implements HasLabel
{
    // Seeing parts of the panel (Area).
    case ViewMembers = 'view.members';
    case ViewCollections = 'view.collections';
    case ViewAccounting = 'view.accounting';
    case ViewGovernance = 'view.governance';
    case ViewRatePlans = 'view.rate_plans';
    case ViewMessaging = 'view.messaging';
    case ViewFinancialReports = 'view.financial_reports';
    case ViewMemberReports = 'view.member_reports';
    case ViewCashReports = 'view.cash_reports';
    case ViewAuditLog = 'view.audit_log';

    // Members.
    case MembersCreate = 'members.create';
    case MembersUpdate = 'members.update';
    case MembersDeactivate = 'members.deactivate';
    case MembersPortalPassword = 'members.portal_password';

    // Collections.
    case PaymentsRecord = 'payments.record';
    case PaymentsApprove = 'payments.approve';
    case PaymentsReverse = 'payments.reverse';
    case DuesGenerate = 'dues.generate';
    case DuesWaive = 'dues.waive';
    case AdvanceRefund = 'advance.refund';
    case ExpensesRecord = 'expenses.record';
    case ExpensesApprove = 'expenses.approve';
    case ExpensesReverse = 'expenses.reverse';
    case TransfersRecord = 'transfers.record';
    case TransfersApprove = 'transfers.approve';
    case TransfersReverse = 'transfers.reverse';

    // Accounting.
    case AccountsManage = 'accounts.manage';
    case JournalsManage = 'journals.manage';
    case JournalsReverse = 'journals.reverse';
    case FiscalYearsOpen = 'fiscal_years.open';
    case PeriodsLock = 'periods.lock';
    case StatementsImport = 'statements.import';
    case StatementsMatch = 'statements.match';
    case IntegrityRun = 'integrity.run';

    // Investments, year-end and dividends.
    case InvestmentsRecord = 'investments.record';
    case InvestmentsApprove = 'investments.approve';
    case InvestmentsIncome = 'investments.income';
    case InvestmentsWriteDown = 'investments.write_down';
    case InvestmentsClose = 'investments.close';
    case YearEndPrepare = 'year_end.prepare';
    case DividendsSettle = 'dividends.settle';

    // Governance and exits.
    case MeetingsManage = 'meetings.manage';
    case ResolutionsManage = 'resolutions.manage';
    case ExitsRequest = 'exits.request';
    case ExitsApprove = 'exits.approve';
    case ExitsPay = 'exits.pay';

    // Settings.
    case RatePlansDraft = 'rate_plans.draft';
    case RatePlansLinkResolution = 'rate_plans.link_resolution';
    case RatePlansCancel = 'rate_plans.cancel';
    case SmsTemplatesEdit = 'sms_templates.edit';
    case SomitiProfileEdit = 'somiti_profile.edit';
    case OpeningImport = 'opening.import';

    /**
     * The roles that hold this permission until the president changes it.
     *
     * @return list<Role>
     */
    public function defaultRoles(): array
    {
        if ($this->area() !== null) {
            return $this->area()->roles();
        }

        return match ($this) {
            self::ViewAuditLog => [Role::SuperAdmin, Role::President, Role::Auditor],
            self::MembersCreate, self::MembersUpdate, self::MembersDeactivate, self::MembersPortalPassword,
            self::MeetingsManage, self::ResolutionsManage, self::ExitsRequest => [Role::Secretary, Role::President],
            self::PaymentsRecord, self::ExpensesRecord, self::TransfersRecord => [Role::Cashier, Role::Accountant, Role::President],
            self::AccountsManage, self::FiscalYearsOpen => [Role::SuperAdmin, Role::Accountant],
            self::IntegrityRun => [Role::SuperAdmin, Role::Accountant, Role::Auditor],
            self::InvestmentsRecord => [Role::Accountant, Role::Secretary, Role::President],
            self::InvestmentsApprove, self::InvestmentsWriteDown, self::ExitsApprove, self::RatePlansCancel => [Role::President],
            self::RatePlansDraft => [Role::SuperAdmin, Role::Secretary, Role::Accountant],
            self::RatePlansLinkResolution => [Role::SuperAdmin, Role::Secretary, Role::Accountant, Role::President],
            self::SmsTemplatesEdit => [Role::SuperAdmin, Role::Secretary],
            self::SomitiProfileEdit, self::OpeningImport => [Role::SuperAdmin, Role::President],
            default => [Role::Accountant, Role::President],
        };
    }

    /**
     * The part of the panel this permission opens, for the "view" permissions.
     */
    public function area(): ?Area
    {
        return match ($this) {
            self::ViewMembers => Area::Members,
            self::ViewCollections => Area::Collections,
            self::ViewAccounting => Area::Accounting,
            self::ViewGovernance => Area::Governance,
            self::ViewRatePlans => Area::RatePlans,
            self::ViewMessaging => Area::Messaging,
            self::ViewFinancialReports => Area::FinancialReports,
            self::ViewMemberReports => Area::MemberReports,
            self::ViewCashReports => Area::CashReports,
            default => null,
        };
    }

    public static function forArea(Area $area): self
    {
        foreach (self::cases() as $permission) {
            if ($permission->area() === $area) {
                return $permission;
            }
        }

        throw new \LogicException("No permission for area {$area->value}.");
    }

    /**
     * Whether it changes anything (everything except looking and running read-only checks).
     */
    public function isWrite(): bool
    {
        return ! str_starts_with($this->value, 'view.') && $this !== self::IntegrityRun;
    }

    /**
     * Whether the role may ever hold it: the auditor stays read-only.
     */
    public function isLockedFor(Role $role): bool
    {
        return $role === Role::Member || ($role === Role::Auditor && $this->isWrite());
    }

    /**
     * Heading the permission is listed under on the settings page.
     */
    public function group(): string
    {
        return match (true) {
            str_starts_with($this->value, 'view.') => 'view',
            str_starts_with($this->value, 'members.') => 'members',
            in_array(explode('.', $this->value)[0], ['payments', 'dues', 'advance', 'expenses', 'transfers'], true) => 'collections',
            in_array(explode('.', $this->value)[0], ['accounts', 'journals', 'fiscal_years', 'periods', 'statements', 'integrity'], true) => 'accounting',
            in_array(explode('.', $this->value)[0], ['investments', 'year_end', 'dividends'], true) => 'investments',
            in_array(explode('.', $this->value)[0], ['meetings', 'resolutions', 'exits'], true) => 'governance',
            default => 'settings',
        };
    }

    /**
     * Staff roles shown as columns on the settings page.
     *
     * @return list<Role>
     */
    public static function editableRoles(): array
    {
        return [Role::President, Role::Secretary, Role::Cashier, Role::Accountant, Role::Auditor, Role::SuperAdmin];
    }

    public function getLabel(): string
    {
        return __('permissions.permission.'.str_replace('.', '_', $this->value));
    }
}
