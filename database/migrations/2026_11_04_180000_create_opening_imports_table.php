<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Go-live import (docs/superpowers/specs/2026-10-05-go-live-import-design.md): the draft/posted
 * import record, the "opening" flag on dues and the "opening" advance/investment ledger kinds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 10);
            $table->date('go_live_month');
            $table->string('file_path');
            $table->string('file_name');
            $table->char('file_sha256', 64);
            $table->jsonb('payload');
            $table->jsonb('errors');
            $table->jsonb('summary');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE opening_imports ADD CONSTRAINT opening_imports_status_valid CHECK (status IN ('draft', 'posted'))");
        DB::statement("CREATE UNIQUE INDEX opening_imports_one_posted ON opening_imports ((true)) WHERE status = 'posted'");
        DB::statement("CREATE UNIQUE INDEX opening_imports_one_draft ON opening_imports ((true)) WHERE status = 'draft'");

        Schema::table('dues', function (Blueprint $table): void {
            $table->boolean('opening')->default(false);
        });

        DB::statement('ALTER TABLE advance_ledger_entries DROP CONSTRAINT advance_entries_kind_valid');
        DB::statement("ALTER TABLE advance_ledger_entries ADD CONSTRAINT advance_entries_kind_valid CHECK (kind IN ('payment_surplus', 'applied_to_due', 'refund', 'reversal', 'fee_waiver', 'exit_transfer', 'exit_settlement', 'opening'))");
        DB::statement('ALTER TABLE investment_ledger_entries DROP CONSTRAINT investment_ledger_kind_valid');
        DB::statement("ALTER TABLE investment_ledger_entries ADD CONSTRAINT investment_ledger_kind_valid CHECK (kind IN ('disbursement', 'top_up', 'impairment', 'closure', 'reversal', 'opening'))");
    }
};
