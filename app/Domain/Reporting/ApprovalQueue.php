<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Accounting\Enums\ExpenseStatus;
use App\Domain\Accounting\Enums\TransferStatus;
use App\Domain\Accounting\Models\Expense;
use App\Domain\Accounting\Models\FundTransfer;
use App\Domain\Accounting\Models\JournalDraft;
use App\Domain\Contributions\Enums\PaymentStatus;
use App\Domain\Contributions\Models\Payment;
use App\Domain\Exits\Enums\ExitStatus;
use App\Domain\Exits\Models\MemberExit;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Models\Investment;
use App\Domain\Members\Registration\Enums\MemberApplicationStatus;
use App\Domain\Members\Registration\Models\MemberApplication;
use App\Domain\Reporting\Data\PendingItem;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Domain\YearEnd\Enums\YearEndStatus;
use App\Domain\YearEnd\Models\YearEnd;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Everything waiting for someone, across the approval queues, and who can act on each item —
 * decided by the same policies that show the buttons. The dashboard, the "waiting for" labels and
 * the daily reminder all read from here, so they never disagree.
 */
final class ApprovalQueue
{
    /** Items read per queue; more than this waiting would itself be the problem to fix. */
    public const int LIMIT = 200;

    /** @var Collection<int, User>|null */
    private ?Collection $staff = null;

    /**
     * @return Collection<int, PendingItem>
     */
    public function all(): Collection
    {
        $items = collect();

        foreach ($this->queues() as [$type, $query, $ability, $since]) {
            foreach ($query->oldest('id')->limit(self::LIMIT)->get() as $record) {
                $moment = $record->getAttribute($since) ?? $record->getAttribute('created_at');
                $items->push(new PendingItem($type, $record, $ability, CarbonImmutable::parse($moment)));
            }
        }

        return $items;
    }

    /**
     * What waits for this user, oldest first.
     *
     * @return Collection<int, PendingItem>
     */
    public function forUser(User $user): Collection
    {
        return $this->all()
            ->filter(fn (PendingItem $item): bool => $user->can($item->ability, $item->record))
            ->sortBy(fn (PendingItem $item): int => $item->since->getTimestamp())
            ->values();
    }

    /**
     * Active staff who can act on the item now.
     *
     * @return Collection<int, User>
     */
    public function whoCanAct(PendingItem $item): Collection
    {
        return $this->staff()->filter(fn (User $user): bool => Gate::forUser($user)->allows($item->ability, $item->record))->values();
    }

    /**
     * Active staff, read once per instance.
     *
     * @return Collection<int, User>
     */
    public function staff(): Collection
    {
        return $this->staff ??= User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', Role::staff()))
            ->whereNull('deactivated_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{0: string, 1: Builder<covariant Model>, 2: string, 3: string}> [type, pending query, ability, "waiting since" column]
     */
    private function queues(): array
    {
        return [
            ['payment', Payment::query()->with('member')->where('status', PaymentStatus::Pending), 'approve', 'created_at'],
            ['expense', Expense::query()->where('status', ExpenseStatus::Pending), 'approve', 'created_at'],
            ['transfer', FundTransfer::query()->where('status', TransferStatus::Pending), 'approve', 'created_at'],
            ['investment', Investment::query()->where('status', InvestmentStatus::Pending), 'approve', 'created_at'],
            ['rate_plan', RatePlan::query()->where('status', RatePlanStatus::PendingApproval), 'approve', 'submitted_at'],
            ['year_end', YearEnd::query()->with('fiscalYear')->where('status', YearEndStatus::Draft), 'approve', 'created_at'],
            ['exit', MemberExit::query()->with('member')->where('status', ExitStatus::Requested), 'approve', 'created_at'],
            ['exit', MemberExit::query()->with('member')->where('status', ExitStatus::Approved), 'pay', 'approved_at'],
            ['journal_draft', JournalDraft::query()->whereNull('journal_entry_id'), 'post', 'created_at'],
            ['registration', MemberApplication::query()->where('status', MemberApplicationStatus::Submitted), 'decide', 'submitted_at'],
        ];
    }
}
