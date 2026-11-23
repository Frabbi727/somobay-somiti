<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * 4141 Entry Premium Income: the fairness charge a late joiner pays. Added to a chart that is
     * already seeded; a fresh install gets it from the seeder.
     */
    public function up(): void
    {
        if (Account::withTrashed()->exists()) {
            app(ChartOfAccountsSeeder::class)->run();
        }
    }
};
