<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Accounting\Enums\StatementLineStatus;
use App\Domain\Accounting\Models\StatementLine;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Governance\Enums\MeetingStatus;
use App\Domain\Governance\Models\Meeting;
use App\Domain\Reporting\ApprovalQueue;
use App\Domain\Reporting\Data\PendingItem;
use App\Domain\Settings\Actions\ApproveRatePlan;
use App\Domain\Settings\Models\SomitiProfile;
use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Clusters\Settings\Resources\RatePlans\RatePlanResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FundTransfers\FundTransferResource;
use App\Filament\Resources\Investments\InvestmentResource;
use App\Filament\Resources\JournalDrafts\JournalDraftResource;
use App\Filament\Resources\Meetings\MeetingResource;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
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

/**
 * "Waiting for you": the queues this user can act on, counted from ApprovalQueue with the same
 * policies that show the buttons — so the cashier sees their own payments awaiting approval, the
 * accountant what they can approve or post, the president what needs the president, an approver in
 * the registration chain the registrations at their step, and so on.
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

        $waiting = app(ApprovalQueue::class)->forUser($user)->countBy(fn (PendingItem $item): string => $item->queue())->all();

        $stats = array_values(array_filter([
            $this->queue($user, $waiting, 'payment.approve', 'payments_to_approve', Heroicon::OutlinedBanknotes, PaymentResource::getUrl()),
            $this->mine($user, Payment::query()->where('status', PaymentStatus::Pending), 'my_pending_payments', PaymentResource::getUrl()),
            $this->queue($user, $waiting, 'registration.decide', 'registrations_to_decide', Heroicon::OutlinedUserPlus, MemberApplicationResource::getUrl()),
            $this->queue($user, $waiting, 'expense.approve', 'expenses_to_approve', Heroicon::OutlinedReceiptPercent, ExpenseResource::getUrl()),
            $this->queue($user, $waiting, 'transfer.approve', 'transfers_to_approve', Heroicon::OutlinedArrowsRightLeft, FundTransferResource::getUrl()),
            $this->queue($user, $waiting, 'journal_draft.post', 'vouchers_to_post', Heroicon::OutlinedDocumentCheck, JournalDraftResource::getUrl()),
            $this->queue($user, $waiting, 'investment.approve', 'investments_to_approve', Heroicon::OutlinedArrowTrendingUp, InvestmentResource::getUrl()),
            $this->queue($user, $waiting, 'rate_plan.approve', 'rate_plans_to_approve', Heroicon::OutlinedCurrencyBangladeshi, RatePlanResource::getUrl()),
            $this->queue($user, $waiting, 'year_end.approve', 'year_ends_to_approve', Heroicon::OutlinedFlag, YearEndResource::getUrl()),
            $this->queue($user, $waiting, 'exit.approve', 'exits_to_approve', Heroicon::OutlinedArrowRightStartOnRectangle, MemberExitResource::getUrl()),
            $this->queue($user, $waiting, 'exit.pay', 'exits_to_pay', Heroicon::OutlinedBanknotes, MemberExitResource::getUrl()),
            $this->unmatchedStatementLines($user),
            $this->upcomingMeetings($user),
        ]));

        return $stats === [] ? [Stat::make(__('dashboard.all_clear'), '✓')->description(__('dashboard.nothing_waiting'))->color('success')] : $stats;
    }

    /**
     * A queue tile: shown to whoever works that queue, even at zero, so "nothing waiting" is visible.
     *
     * @param  array<string, int>  $waiting  queue => items this user can act on
     */
    private function queue(User $user, array $waiting, string $queue, string $key, Heroicon $icon, string $url): ?Stat
    {
        $count = $waiting[$queue] ?? 0;

        if ($count === 0 && ! $this->worksQueue($user, $queue)) {
            return null;
        }

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
     * Whether the user works this queue (from their permissions), to show its tile at zero.
     */
    private function worksQueue(User $user, string $queue): bool
    {
        return match ($queue) {
            'payment.approve' => $user->may(Permission::PaymentsApprove),
            'expense.approve' => $user->may(Permission::ExpensesApprove),
            'transfer.approve' => $user->may(Permission::TransfersApprove),
            'investment.approve' => $user->may(Permission::InvestmentsApprove),
            'journal_draft.post' => $user->may(Permission::JournalsManage),
            'exit.approve' => $user->may(Permission::ExitsApprove),
            'exit.pay' => $user->may(Permission::ExitsPay),
            'rate_plan.approve' => $user->hasAnyOf(...ApproveRatePlan::REQUIRED_ROLES),
            'year_end.approve' => $user->hasAnyOf(Role::President, Role::Accountant),
            'registration.decide' => $user->hasAnyOf(...SomitiProfile::current()->registrationApprovalChain()),
            default => false,
        };
    }
}
