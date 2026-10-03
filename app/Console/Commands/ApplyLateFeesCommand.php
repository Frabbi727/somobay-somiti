<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Contributions\Actions\ApplyLateFees;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('somiti:late-fees:apply {date? : YYYY-MM-DD, defaults to today in Asia/Dhaka}')]
#[Description('Charge late fees on dues past their grace period (safe to run again)')]
final class ApplyLateFeesCommand extends Command
{
    public function handle(ApplyLateFees $apply): int
    {
        $argument = $this->argument('date');
        $date = is_string($argument) && $argument !== ''
            ? CarbonImmutable::parse($argument, YearMonth::TIMEZONE)
            : CarbonImmutable::now(YearMonth::TIMEZONE);

        ['count' => $count, 'total' => $total] = $apply($date);

        $this->info(sprintf('%d late fee(s) charged, %s, for %s.', $count, $total->format('en'), $date->toDateString()));

        return self::SUCCESS;
    }
}
