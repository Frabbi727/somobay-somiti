<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Contributions\Actions\GenerateMonthlyDues;
use App\Domain\Shared\Exceptions\DomainRuleViolation;
use App\Support\Time\YearMonth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('somiti:dues:generate {month? : YYYY-MM, defaults to the current month}')]
#[Description('Generate monthly deposit and service-charge dues (safe to run again)')]
final class GenerateDuesCommand extends Command
{
    public function handle(GenerateMonthlyDues $generate): int
    {
        $argument = $this->argument('month');
        $month = is_string($argument) && $argument !== '' ? YearMonth::parse($argument) : YearMonth::current();

        try {
            $result = $generate($month);
        } catch (DomainRuleViolation $violation) {
            $this->error($violation->getMessage());

            return self::FAILURE;
        }

        $this->table(['Month', 'Plan', 'Members', 'Shares', 'New dues', 'Amount', 'Already existed'], [[
            (string) $result->month,
            $result->plan->code,
            $result->memberCount,
            $result->shareCount,
            $result->newCount(),
            $result->newTotal()->format('en'),
            $result->alreadyExisting,
        ]]);

        return self::SUCCESS;
    }
}
