<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank and wallet statements (Phase 8): imported lines are matched one-to-one with the journal
     * lines of the same account, so reconciliation shows what the bank has that the books lack and
     * vice versa. Imports never change the books.
     */
    public function up(): void
    {
        Schema::create('statement_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('method', 10);
            $table->string('filename');
            $table->string('file_path');
            $table->char('file_sha256', 64);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->bigInteger('closing_balance_poisha')->nullable();
            $table->unsignedInteger('lines_count')->default(0);
            $table->unsignedInteger('duplicates_skipped')->default(0);
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['method', 'file_sha256']);
        });

        DB::statement("ALTER TABLE statement_imports ADD CONSTRAINT statement_imports_method_valid CHECK (method IN ('bank', 'bkash', 'nagad'))");

        Schema::create('statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('statement_import_id')->constrained()->restrictOnDelete();
            $table->string('method', 10);
            $table->unsignedInteger('line_no');
            $table->date('transacted_on');
            $table->text('description')->nullable();
            $table->string('reference', 100)->nullable();
            $table->bigInteger('amount_poisha');
            $table->bigInteger('balance_poisha')->nullable();
            $table->char('fingerprint', 64);
            $table->string('status', 10)->default('unmatched');
            $table->foreignId('journal_line_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('matched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('matched_at')->nullable();
            $table->text('ignore_reason')->nullable();
            $table->timestamps();

            $table->unique(['method', 'fingerprint']);
            $table->index(['method', 'status', 'transacted_on']);
        });

        DB::statement("ALTER TABLE statement_lines ADD CONSTRAINT statement_lines_status_valid CHECK (status IN ('unmatched', 'matched', 'ignored'))");
        DB::statement('ALTER TABLE statement_lines ADD CONSTRAINT statement_lines_amount_nonzero CHECK (amount_poisha <> 0)');
        DB::statement("ALTER TABLE statement_lines ADD CONSTRAINT statement_lines_match_consistent CHECK ((status = 'matched') = (journal_line_id IS NOT NULL))");
        DB::statement("ALTER TABLE statement_lines ADD CONSTRAINT statement_lines_ignore_reason CHECK (status <> 'ignored' OR ignore_reason IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statement_lines');
        Schema::dropIfExists('statement_imports');
    }
};
