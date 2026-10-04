<?php

declare(strict_types=1);

namespace App\Domain\YearEnd\Actions;

use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Domain\YearEnd\Enums\DividendSettlement;
use App\Domain\YearEnd\Enums\DividendStatus;
use App\Domain\YearEnd\Models\YearEnd;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Bulk: credits every unpaid dividend of a year-end to the members' savings, each line in its own
 * transaction (§8.4 bulk rule), and reports how many succeeded.
 */
final class CreditDividendsToSavings
{
    public function __construct(private readonly SettleDividend $settle) {}

    /**
     * @return array{credited: int, failed: int}
     */
    public function __invoke(User $actor, YearEnd $yearEnd, ?CarbonImmutable $on = null): array
    {
        $credited = 0;
        $failed = 0;

        foreach ($yearEnd->dividendLines()->where('status', DividendStatus::Unpaid)->orderBy('member_id')->get() as $line) {
            try {
                ($this->settle)($actor, $line, DividendSettlement::Savings, on: $on);
                $credited++;
            } catch (DomainRuleViolation|AuthorizationException) {
                $failed++;
            }
        }

        return ['credited' => $credited, 'failed' => $failed];
    }
}
