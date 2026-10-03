<?php

declare(strict_types=1);

namespace App\Domain\Contributions\Services;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Data\JournalLineData;
use App\Domain\Accounting\Services\Accounts;
use App\Domain\Contributions\Enums\DueStatus;
use App\Domain\Contributions\Enums\DueType;
use App\Domain\Contributions\Models\Due;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Turns allocations to dues into journal credits (§5.5) and keeps dues' paid amounts in step.
 * Income is recognised when settled: deposits credit member savings, fees credit income.
 */
final class DuePosting
{
    public function __construct(private readonly Accounts $accounts) {}

    public function accountCodeFor(DueType $type): string
    {
        return match ($type) {
            DueType::Deposit => AccountCode::MEMBER_SAVINGS,
            DueType::ServiceCharge => AccountCode::SERVICE_CHARGE_INCOME,
            DueType::Registration => AccountCode::REGISTRATION_FEE_INCOME,
            DueType::LateFee => AccountCode::LATE_FEE_INCOME,
        };
    }

    /**
     * One credit line per account (member-tagged for member accounts), in a stable order.
     *
     * @param  list<array{due: Due, amount: Money}>  $allocations
     * @return list<JournalLineData>
     */
    public function creditLines(array $allocations, int $memberId): array
    {
        $totals = [];

        foreach ($allocations as ['due' => $due, 'amount' => $amount]) {
            $code = $this->accountCodeFor($due->type);
            $totals[$code] = ($totals[$code] ?? Money::zero())->plus($amount);
        }

        ksort($totals);
        $lines = [];

        foreach ($totals as $code => $amount) {
            $account = $this->accounts->byCode((string) $code);
            $lines[] = JournalLineData::credit($account, $amount, $account->requires_member ? $memberId : null);
        }

        return $lines;
    }

    public function settle(Due $due, Money $amount): void
    {
        $paid = $due->paid_poisha->plus($amount);
        $settled = $paid->equals($due->amount_poisha);

        $due->forceFill([
            'paid_poisha' => $paid,
            'status' => $settled ? DueStatus::Settled : DueStatus::Open,
            'closed_at' => $settled ? CarbonImmutable::now() : null,
        ])->save();
    }

    public function unsettle(Due $due, Money $amount): void
    {
        $due->forceFill([
            'paid_poisha' => $due->paid_poisha->minus($amount),
            'status' => $due->status === DueStatus::Settled ? DueStatus::Open : $due->status,
            'closed_at' => null,
        ])->save();
    }
}
