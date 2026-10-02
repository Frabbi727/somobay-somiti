<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal storage (SOMITI_SPEC.md §6.5). Posting rules, voucher numbering and immutability
     * triggers are added with the PostJournal action in P1.S2.
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('period_id')->constrained()->restrictOnDelete();
            $table->string('voucher_type', 2);
            $table->string('voucher_no', 20)->unique();
            $table->date('entry_date');
            $table->text('narration');
            $table->string('status', 10)->default('posted');
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->nullableMorphs('source');
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at');
            $table->char('hash', 64)->nullable();
            $table->timestamps();

            $table->index(['fiscal_year_id', 'voucher_type']);
            $table->index('entry_date');
        });

        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_voucher_type_valid CHECK (voucher_type IN ('RV', 'PV', 'JV', 'CV'))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status_valid CHECK (status IN ('posted', 'reversed'))");

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->bigInteger('debit_poisha')->default(0);
            $table->bigInteger('credit_poisha')->default(0);
            $table->string('memo')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['journal_entry_id', 'line_no']);
            $table->index(['account_id', 'member_id']);
        });

        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_sided CHECK ((debit_poisha > 0 AND credit_poisha = 0) OR (debit_poisha = 0 AND credit_poisha > 0))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
