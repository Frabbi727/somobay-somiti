<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exits\Actions\EndSharesOfExitedMembers;
use App\Support\Money\Money;
use App\Support\SystemUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('somiti:exits:end-share-lots {--apply : Save the changes (without it nothing is saved)}')]
#[Description('End the shares of members whose exit was approved before exits ended shares; lists dividends paid to them after they left')]
final class EndExitedMemberSharesCommand extends Command
{
    public function handle(EndSharesOfExitedMembers $repair): int
    {
        $apply = (bool) $this->option('apply');
        $report = $repair(SystemUser::get(), $apply);

        $this->line($apply ? 'Changes saved.' : 'Dry run: nothing was saved. Run again with --apply to save.');

        $this->table(['Member', 'Shares ended'], array_map(fn (array $row): array => [$row['member_no'], $row['shares']], $report['fixed']));

        if ($report['skipped'] !== []) {
            $this->warn('Skipped (needs a manual look):');
            $this->table(['Member', 'Reason'], array_map(fn (array $row): array => [$row['member_no'], $row['reason']], $report['skipped']));
        }

        if ($report['dividends'] !== []) {
            $this->warn('Dividend lines from year-ends posted after these members exited (not changed; a committee decision):');
            $this->table(['Member', 'Year-end', 'Amount', 'Status'], array_map(fn (array $row): array => [
                $row['member_no'],
                $row['year_end_id'],
                Money::ofPoisha($row['amount_poisha'])->format('en'),
                $row['status'],
            ], $report['dividends']));
        }

        return self::SUCCESS;
    }
}
