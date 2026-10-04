<?php

declare(strict_types=1);

namespace App\Domain\Investments\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Investments\Enums\InvestmentStatus;
use App\Domain\Investments\Enums\InvestmentType;
use App\Support\Money\Bps;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Cooperative Societies Act s.33 limits, as WARNINGS only (SOMITI_SPEC.md Phase 10). Rules come
 * from somiti.investment_limits: [type, max_bps, of_account] — e.g. company securities up to 10%
 * of the accumulated surplus (3901). Confirm the rules with your auditor and bylaws.
 */
final class InvestmentLimits
{
    /**
     * @return list<string> human-readable warnings; empty when within every configured limit
     */
    public function warningsFor(InvestmentType $type, Money $newPrincipal, ?int $excludingInvestmentId = null): array
    {
        $warnings = [];

        foreach ((array) config('somiti.investment_limits') as $rule) {
            if (! is_array($rule) || ($rule['type'] ?? null) !== $type->value) {
                continue;
            }

            $account = Account::query()->where('code', (string) ($rule['of_account'] ?? ''))->first();
            $maxBps = (int) ($rule['max_bps'] ?? 0);

            if ($account === null || $maxBps <= 0) {
                continue;
            }

            // Credit-normal equity: base = credits − debits.
            $base = Money::ofPoisha((int) DB::table('journal_lines')->where('account_id', $account->id)->sum(DB::raw('credit_poisha - debit_poisha')));
            $limit = $base->isPositive() ? $base->percentOfBps(Bps::of($maxBps)) : Money::zero();

            $existing = Money::ofPoisha((int) DB::table('investment_ledger_entries as l')
                ->join('investments as i', 'i.id', '=', 'l.investment_id')
                ->where('i.type', $type->value)
                ->where('i.status', InvestmentStatus::Active->value)
                ->when($excludingInvestmentId !== null, fn ($query) => $query->where('i.id', '!=', $excludingInvestmentId))
                ->sum('l.delta_poisha'));

            $total = $existing->plus($newPrincipal);

            if ($total->isGreaterThan($limit)) {
                $warnings[] = (string) __('investments.warnings.limit', [
                    'type' => $type->getLabel(),
                    'total' => $total->format(app()->getLocale()),
                    'limit' => $limit->format(app()->getLocale()),
                    'percent' => Bps::of($maxBps)->format(app()->getLocale()),
                    'account' => $account->displayName(),
                ]);
            }
        }

        return $warnings;
    }
}
