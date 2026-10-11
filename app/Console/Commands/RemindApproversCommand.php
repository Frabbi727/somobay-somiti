<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\Actions\SendApprovalReminders;
use App\Support\Time\YearMonth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('somiti:approvals:remind')]
#[Description('Remind people of approvals that have waited too long (one bell message a day each)')]
final class RemindApproversCommand extends Command
{
    public function handle(SendApprovalReminders $remind): int
    {
        $count = $remind(CarbonImmutable::now(YearMonth::TIMEZONE));

        $this->info("{$count} person(s) reminded.");

        return self::SUCCESS;
    }
}
