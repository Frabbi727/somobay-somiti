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
use App\Domain\Reporting\Data\PendingItem;
use App\Domain\Reporting\Data\SubmissionRow;
use App\Domain\Settings\Enums\RatePlanStatus;
use App\Domain\Settings\Models\RatePlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What the user recently entered and where each item stands — and, while it waits, who can act on
 * it now — so nobody has to ask "has my entry been approved yet?".
 */
final class MySubmissions
{
    public function __construct(
        private readonly RecordDescriptor $describe,
        private readonly ApprovalQueue $queue,
    ) {}

    /**
     * @return list<SubmissionRow>
     */
    public function recent(User $user, int $limit = 8): array
    {
        /** @var Collection<int, Model> $records */
        $records = collect();

        foreach ($this->sources() as [$model, $makerColumn]) {
            $records = $records->merge($model::query()->where($makerColumn, $user->id)->latest('id')->limit($limit)->get());
        }

        return array_values($records
            ->sortByDesc(fn (Model $record): int => CarbonImmutable::parse($record->getAttribute('created_at'))->getTimestamp())
            ->take($limit)
            ->map(fn (Model $record): SubmissionRow => $this->row($record))
            ->all());
    }

    private function row(Model $record): SubmissionRow
    {
        $type = $this->describe->type($record);
        $params = $this->describe->params($record);
        $ability = $this->pendingAbility($record);
        [$label, $color] = $this->status($record);

        $waitingFor = null;

        if ($ability !== null) {
            $people = $this->queue->whoCanAct(new PendingItem($type, $record, $ability, CarbonImmutable::now()));
            $waitingFor = $people->isEmpty() ? (string) __('dashboard.nobody_can_act') : $people->pluck('name')->implode(', ');
        }

        return new SubmissionRow(
            type: (string) __('dashboard.type.'.$type),
            reference: $params['reference'] ?? '',
            amount: $params['amount'] ?? '',
            statusLabel: $label,
            statusColor: $color,
            enteredAt: CarbonImmutable::parse($record->getAttribute('created_at')),
            waitingFor: $waitingFor,
            url: $this->describe->url($record),
        );
    }

    /**
     * The ability someone needs to move the record on, while it is still waiting.
     */
    private function pendingAbility(Model $record): ?string
    {
        return match (true) {
            $record instanceof JournalDraft => $record->isPosted() ? null : 'post',
            $record instanceof RatePlan => $record->status === RatePlanStatus::PendingApproval ? 'approve' : null,
            $record instanceof MemberExit => match ($record->status) {
                ExitStatus::Requested => 'approve',
                ExitStatus::Approved => 'pay',
                default => null,
            },
            $record instanceof Payment => $record->status === PaymentStatus::Pending ? 'approve' : null,
            $record instanceof Expense => $record->status === ExpenseStatus::Pending ? 'approve' : null,
            $record instanceof FundTransfer => $record->status === TransferStatus::Pending ? 'approve' : null,
            $record instanceof Investment => $record->status === InvestmentStatus::Pending ? 'approve' : null,
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: string} status label and badge color
     */
    private function status(Model $record): array
    {
        if ($record instanceof JournalDraft) {
            return $record->isPosted() ? [(string) __('dashboard.posted'), 'success'] : [(string) __('dashboard.draft'), 'warning'];
        }

        $status = $record->getAttribute('status');
        $label = $status instanceof HasLabel ? $status->getLabel() : null;
        $color = $status instanceof HasColor ? $status->getColor() : null;

        return [is_string($label) ? $label : '', is_string($color) ? $color : 'gray'];
    }

    /**
     * @return list<array{0: class-string<Model>, 1: string}> [model, column naming who entered it]
     */
    private function sources(): array
    {
        return [
            [Payment::class, 'recorded_by'],
            [Expense::class, 'recorded_by'],
            [FundTransfer::class, 'recorded_by'],
            [Investment::class, 'recorded_by'],
            [RatePlan::class, 'created_by'],
            [MemberExit::class, 'requested_by'],
            [JournalDraft::class, 'created_by'],
        ];
    }
}
