<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profit received on investments (Phase 10): gross profit (4201), tax/duty deducted at source
     * (5106) and the net received into bank or cash, each tied to its receipt voucher.
     */
    public function up(): void
    {
        Schema::create('investment_income', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('investment_id')->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->string('received_into', 10);
            $table->unsignedBigInteger('gross_poisha');
            $table->unsignedBigInteger('tax_deducted_poisha')->default(0);
            $table->string('reference', 60)->nullable();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index(['investment_id', 'received_on']);
        });

        DB::statement("ALTER TABLE investment_income ADD CONSTRAINT investment_income_into_valid CHECK (received_into IN ('cash', 'bank', 'bkash', 'nagad'))");
        DB::statement('ALTER TABLE investment_income ADD CONSTRAINT investment_income_amounts CHECK (gross_poisha > 0 AND tax_deducted_poisha >= 0 AND tax_deducted_poisha < gross_poisha)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER investment_income_append_only BEFORE UPDATE OR DELETE ON investment_income
                FOR EACH ROW EXECUTE FUNCTION investment_ledger_append_only();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investment_income');
    }
};
