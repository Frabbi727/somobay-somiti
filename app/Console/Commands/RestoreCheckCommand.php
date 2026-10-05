<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\Services\RestoreCheck;
use Illuminate\Console\Command;

/**
 * Monthly: restores the newest backup into a throwaway database and checks the books (never touches live data).
 */
final class RestoreCheckCommand extends Command
{
    protected $signature = 'somiti:backup:check-restore';

    protected $description = 'Prove the newest backup can be restored: restore it into a throwaway database and check the books';

    public function handle(RestoreCheck $check): int
    {
        $result = $check->run();

        if ($result['passed']) {
            $this->info("Restore check passed: {$result['backup']} — {$result['members']} members, {$result['vouchers']} vouchers, books balance.");

            return self::SUCCESS;
        }

        $this->error('Restore check FAILED: '.$result['error']);

        return self::FAILURE;
    }
}
