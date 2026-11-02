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
     * Selling or closing an investment keeps its sale price, the selling expenses and the gain or
     * loss, posted to their own accounts (4211 gain on sale, 5202 loss on sale) so rent and other
     * profit (4201) stay separate from the gain on the sale itself.
     */
    public function up(): void
    {
        if (Account::query()->exists()) {
            (new ChartOfAccountsSeeder)->run();
        }

        Schema::table('investments', function (Blueprint $table): void {
            $table->unsignedBigInteger('sale_price_poisha')->nullable();
            $table->unsignedBigInteger('sale_expenses_poisha')->nullable();
            $table->bigInteger('sale_gain_poisha')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE investments ADD CONSTRAINT investments_sale_expenses_within_price
                CHECK (sale_expenses_poisha IS NULL OR (sale_price_poisha IS NOT NULL AND sale_expenses_poisha <= sale_price_poisha));
            ALTER TABLE investments ADD CONSTRAINT investments_sale_only_when_closed
                CHECK (sale_price_poisha IS NULL OR status = 'closed');
            SQL);
    }
};
