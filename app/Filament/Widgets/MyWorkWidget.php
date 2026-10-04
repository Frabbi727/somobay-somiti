<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Models\Expense;
use App\Domain\Accounting\Models\FundTransfer;
use App\Domain\Accounting\Models\StatementLine;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\YearEnd;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\RatePlans\RatePlanResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FundTransfers\FundTransferResource;
use App\Filament\Resources\Investments\InvestmentResource;
use App\Filament\Resources\Meetings\MeetingResource;
use App\Filament\Resources\MemberExits\MemberExitResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\StatementImports\StatementImportResource;
use App\Filament\Resources\YearEnds\YearEndResource;
use App\Filament\Support\Display;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Waiting for you": the queues this user can act on, counted with the same policies that show the
 * buttons — so the cashier sees their own payments awaiting approval, the accountant what they can
 * approve, the president what needs the president, and so on.
 */
final class MyWorkWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected function getHeading(): string
    {
        return __('dashboard.my_work');
    }

    public static function canView(): bool
    {
        return auth()->user() instanceof User && auth()->user()->isStaff();
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $stats = array_values(array_filter([
            $this->canApprove($user, Payment::query()->where('status', PaymentStatus::Pending), 'approve', 'payments_to_approve', Heroicon::OutlinedBanknotes, PaymentResource::getUrl()),
            $this->mine($user, Payment::query()->where('status', PaymentStatus::Pending), 'my_pending_payments', PaymentResource::getUrl()),
            $this->canApprove($user, Expense::query()->where('status', ExpenseStatus::Pending), 'approve', 'expenses_to_approve', Heroicon::OutlinedReceiptPercent, ExpenseResource::getUrl()),
            $this->canApprove($user, FundTransfer::query()->where('status', TransferStatus::Pending), 'approve', 'transfers_to_approve', Heroicon::OutlinedArrowsRightLeft, FundTransferResource::getUrl()),
            $this->canApprove($user, Investment::query()->where('status', InvestmentStatus::Pending), 'approve', 'investments_to_approve', Heroicon::OutlinedArrowTrendingUp, InvestmentResource::getUrl()),
            $this->canApprove($user, RatePlan::query()->where('status', RatePlanStatus::PendingApproval), 'approve', 'rate_plans_to_approve', Heroicon::OutlinedCurrencyBangladeshi, RatePlanResource::getUrl()),
            $this->canApprove($user, YearEnd::query()->where('status', YearEndStatus::Draft), 'approve', 'year_ends_to_approve', Heroicon::OutlinedFlag, YearEndResource::getUrl()),
            $this->canApprove($user, MemberExit::query()->where('status', ExitStatus::Requested), 'approve', 'exits_to_approve', Heroicon::OutlinedArrowRightStartOnRectangle, MemberExitResource::getUrl()),
            $this->canApprove($user, MemberExit::query()->where('status', ExitStatus::Approved), 'pay', 'exits_to_pay', Heroicon::OutlinedBanknotes, MemberExitResource::getUrl()),
            $this->unmatchedStatementLines($user),
            $this->upcomingMeetings($user),
        ]));

        return $stats === [] ? [Stat::make(__('dashboard.all_clear'), '✓')->description(__('dashboard.nothing_waiting'))->color('success')] : $stats;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function canApprove(User $user, Builder $query, string $ability, string $key, Heroicon $icon, string $url): ?Stat
    {
        $records = $query->limit(500)->get();

        // Shown to whoever could act on such a record at all (zero is still useful to them).
        $relevant = $records->isNotEmpty()
            ? $records->contains(fn (Model $record): bool => $user->can($ability, $record))
            : $this->roleActsOn($user, $key);

        if (! $relevant) {
            return null;
        }

        $count = $records->filter(fn (Model $record): bool => $user->can($ability, $record))->count();

        return Stat::make(__('dashboard.'.$key), Display::digits($count))
            ->icon($icon)
            ->color($count > 0 ? 'warning' : 'gray')
            ->description(__($count > 0 ? 'dashboard.needs_you' : 'dashboard.none_waiting'))
            ->url($url);
    }

    /**
     * @param  Builder<Payment>  $query
     */
    private function mine(User $user, Builder $query, string $key, string $url): ?Stat
    {
        if (! $user->hasAnyOf(Role::Cashier)) {
            return null;
        }

        $count = (clone $query)->where('recorded_by', $user->id)->count();

        return Stat::make(__('dashboard.'.$key), Display::digits($count))
            ->icon(Heroicon::OutlinedClock)
            ->color($count > 0 ? 'info' : 'gray')
            ->description(__('dashboard.waiting_for_checker'))
            ->url($url);
    }

    private function unmatchedStatementLines(User $user): ?Stat
    {
        if (! $user->can('match', new StatementLine(['status' => StatementLineStatus::Unmatched]))) {
            return null;
        }

        $count = StatementLine::query()->where('status', StatementLineStatus::Unmatched)->count();

        return Stat::make(__('dashboard.statement_lines'), Display::digits($count))
            ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
            ->color($count > 0 ? 'warning' : 'gray')
            ->url(StatementImportResource::getUrl());
    }

    private function upcomingMeetings(User $user): ?Stat
    {
        if (! $user->can('create', Meeting::class)) {
            return null;
        }

        $next = Meeting::query()->where('status', MeetingStatus::Scheduled)->orderBy('scheduled_at')->first();

        return Stat::make(__('dashboard.next_meeting'), $next === null ? '—' : Display::dateTime($next->scheduled_at))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->description($next === null ? (string) __('dashboard.no_meeting') : $next->title)
            ->url(MeetingResource::getUrl());
    }

    /**
     * Whether the user works this queue (their permissions), for showing an empty (zero) tile, for showing an empty (zero) tile.
     */
    private function roleActsOn(User $user, string $key): bool
    {
        return match ($key) {
            'payments_to_approve' => $user->may(Permission::PaymentsApprove),
            'expenses_to_approve' => $user->may(Permission::ExpensesApprove),
            'transfers_to_approve' => $user->may(Permission::TransfersApprove),
            'investments_to_approve' => $user->may(Permission::InvestmentsApprove),
            'exits_to_approve' => $user->may(Permission::ExitsApprove),
            'rate_plans_to_approve' => $user->hasAnyOf(...ApproveRatePlan::REQUIRED_ROLES),
            'year_ends_to_approve' => $user->hasAnyOf(Role::President, Role::Accountant),
            'exits_to_pay' => $user->may(Permission::ExitsPay),
            default => false,
        };
    }
}
