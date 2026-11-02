<?php

declare(strict_types=1);

use App\Domain\Accounting\Models\Account;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Investments in land, shops, commercial property, real estate and businesses, each with its
     * own 13xx control account, and a name for every investment (older rows keep none).
     */
    public function up(): void
    {
        if (Account::query()->exists()) {
            (new ChartOfAccountsSeeder)->run();
        }

        Schema::table('investments', function (Blueprint $table): void {
            $table->string('name')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE investments DROP CONSTRAINT investments_type_valid;
            ALTER TABLE investments ADD CONSTRAINT investments_type_valid CHECK (type IN (
                'fixed_deposit', 'savings_certificate', 'government_securities', 'company_securities', 'cooperative',
                'land', 'shop', 'commercial_property', 'real_estate', 'business', 'other'
            ));
            SQL);
    }
};
