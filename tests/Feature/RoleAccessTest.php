<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\RatePlans\RatePlanResource;
use App\Filament\Clusters\Settings\Resources\SmsTemplates\SmsTemplateResource;
use App\Filament\Clusters\Settings\Resources\Users\UserResource;
use App\Filament\Pages\Collections\CollectPayment;
use App\Filament\Pages\Reports\CashBookPage;
use App\Filament\Pages\Reports\IntegrityReport;
use App\Filament\Pages\Reports\MemberStatementPage;
use App\Filament\Pages\Reports\TrialBalanceReport;
use App\Filament\Pages\YearEnd\YearEndWizard;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Investments\InvestmentResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\Meetings\MeetingResource;
use App\Filament\Resources\MemberExits\MemberExitResource;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\YearEnds\YearEndResource;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;

/*
| What each role sees in the staff panel (App\Enums\Area). Pages not listed for a role must be refused.
*/

/**
 * @return array<string, Closure(): string>
 */
function panelPages(): array
{
    return [
        'members' => fn (): string => MemberResource::getUrl(),
        'payments' => fn (): string => PaymentResource::getUrl(),
        'collect' => fn (): string => CollectPayment::getUrl(),
        'expenses' => fn (): string => ExpenseResource::getUrl(),
        'vouchers' => fn (): string => JournalEntryResource::getUrl(),
        'investments' => fn (): string => InvestmentResource::getUrl(),
        'meetings' => fn (): string => MeetingResource::getUrl(),
        'exits' => fn (): string => MemberExitResource::getUrl(),
        'year_end_wizard' => fn (): string => YearEndWizard::getUrl(),
        'year_ends' => fn (): string => YearEndResource::getUrl(),
        'rate_plans' => fn (): string => RatePlanResource::getUrl(),
        'sms_templates' => fn (): string => SmsTemplateResource::getUrl(),
        'users' => fn (): string => UserResource::getUrl(),
        'trial_balance' => fn (): string => TrialBalanceReport::getUrl(),
        'member_statement' => fn (): string => MemberStatementPage::getUrl(),
        'cash_book' => fn (): string => CashBookPage::getUrl(),
        'integrity' => fn (): string => IntegrityReport::getUrl(),
    ];
}

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->seed(ChartOfAccountsSeeder::class);
});

it('shows each role its own part of the panel and nothing else', function (Role $role, array $allowed): void {
    $this->actingAs(userWithRole($role));

    $this->get(Dashboard::getUrl())->assertOk()->assertSee(__('dashboard.my_work', [], 'bn'));

    foreach (panelPages() as $name => $url) {
        $response = $this->get($url());

        if (in_array($name, $allowed, true)) {
            expect($response->status())->toBe(200, "{$role->value} should open {$name}");
        } else {
            expect($response->status())->toBe(403, "{$role->value} should not open {$name}");
        }
    }
})->with([
    'cashier' => [Role::Cashier, ['members', 'payments', 'collect', 'expenses', 'member_statement', 'cash_book']],
    'secretary' => [Role::Secretary, ['members', 'meetings', 'exits', 'rate_plans', 'sms_templates', 'member_statement']],
    'accountant' => [Role::Accountant, ['members', 'payments', 'collect', 'expenses', 'vouchers', 'investments', 'meetings', 'exits', 'year_end_wizard', 'year_ends', 'rate_plans', 'trial_balance', 'member_statement', 'cash_book', 'integrity']],
    'president' => [Role::President, ['members', 'payments', 'collect', 'expenses', 'vouchers', 'investments', 'meetings', 'exits', 'year_end_wizard', 'year_ends', 'rate_plans', 'trial_balance', 'member_statement', 'cash_book', 'integrity']],
    'auditor' => [Role::Auditor, ['members', 'payments', 'expenses', 'vouchers', 'investments', 'meetings', 'exits', 'year_ends', 'rate_plans', 'sms_templates', 'trial_balance', 'member_statement', 'cash_book', 'integrity']],
    'super admin' => [Role::SuperAdmin, ['members', 'payments', 'expenses', 'vouchers', 'investments', 'meetings', 'exits', 'year_ends', 'rate_plans', 'sms_templates', 'users', 'trial_balance', 'member_statement', 'cash_book', 'integrity']],
]);

it('shows money figures on the dashboard only to roles that handle money', function (): void {
    $this->actingAs(userWithRole(Role::Secretary));
    $this->get(Dashboard::getUrl())->assertOk()->assertDontSee(__('dashboard.cash_in_hand', [], 'bn'));

    $this->actingAs(userWithRole(Role::Cashier));
    $this->get(Dashboard::getUrl())->assertOk()->assertSee(__('dashboard.cash_in_hand', [], 'bn'))->assertSee(__('dashboard.my_pending_payments', [], 'bn'));

    $this->actingAs(userWithRole(Role::Accountant));
    $this->get(Dashboard::getUrl())->assertOk()->assertSee(__('dashboard.payments_to_approve', [], 'bn'));
});
