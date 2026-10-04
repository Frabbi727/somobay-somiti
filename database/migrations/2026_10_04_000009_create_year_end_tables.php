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
     * Year-end (Phase 11): one closing per fiscal year, its statutory appropriation and the
     * dividend lines it creates (Σ lines = pool, enforced by the integrity checks).
     */
    public function up(): void
    {
        if (Account::query()->exists()) {
            (new ChartOfAccountsSeeder)->run();
        }

        Schema::create('year_ends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_year_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 10)->default('draft');
            $table->bigInteger('net_profit_poisha');
            $table->unsignedBigInteger('prior_loss_poisha')->default(0);
            $table->unsignedBigInteger('loss_offset_poisha')->default(0);
            $table->unsignedInteger('reserve_bps');
            $table->unsignedInteger('development_fund_bps');
            $table->unsignedInteger('bad_debt_fund_bps');
            $table->unsignedInteger('other_funds_bps');
            $table->jsonb('appropriation');
            $table->unsignedBigInteger('dividend_pool_poisha')->default(0);
            $table->unsignedBigInteger('total_share_months')->default(0);
            $table->char('fingerprint', 64);
            $table->foreignId('resolution_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('president_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('president_approved_at')->nullable();
            $table->foreignId('accountant_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('accountant_approved_at')->nullable();
            $table->foreignId('closing_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('appropriation_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE year_ends ADD CONSTRAINT year_ends_status_valid CHECK (status IN ('draft', 'posted'))");
        DB::statement("ALTER TABLE year_ends ADD CONSTRAINT year_ends_posted_has_entries CHECK (status <> 'posted' OR (closing_journal_entry_id IS NOT NULL AND posted_at IS NOT NULL))");
        DB::statement('ALTER TABLE year_ends ADD CONSTRAINT year_ends_two_approvers CHECK (president_approved_by IS NULL OR accountant_approved_by IS NULL OR president_approved_by <> accountant_approved_by)');

        Schema::create('dividend_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('year_end_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('share_months');
            $table->unsignedBigInteger('amount_poisha');
            $table->string('status', 10)->default('unpaid');
            $table->string('settled_via', 10)->nullable();
            $table->foreignId('settlement_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['year_end_id', 'member_id']);
            $table->index(['member_id', 'status']);
        });

        DB::statement("ALTER TABLE dividend_lines ADD CONSTRAINT dividend_lines_status_valid CHECK (status IN ('unpaid', 'paid', 'credited'))");
        DB::statement("ALTER TABLE dividend_lines ADD CONSTRAINT dividend_lines_settled CHECK ((status = 'unpaid') = (settlement_journal_entry_id IS NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION year_end_records_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF TG_TABLE_NAME = 'year_ends' AND OLD.status = 'draft' THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'Year-end records are never deleted.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_TABLE_NAME = 'year_ends' AND OLD.status = 'posted' THEN
                    RAISE EXCEPTION 'A posted year-end cannot change.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_TABLE_NAME = 'dividend_lines' AND (
                    NEW.year_end_id <> OLD.year_end_id OR NEW.member_id <> OLD.member_id
                    OR NEW.share_months <> OLD.share_months OR NEW.amount_poisha <> OLD.amount_poisha
                    OR (OLD.status <> 'unpaid' AND NEW.status IS DISTINCT FROM OLD.status)
                ) THEN
                    RAISE EXCEPTION 'Dividend lines are fixed; only an unpaid line can be settled once.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER year_ends_guard BEFORE UPDATE OR DELETE ON year_ends
                FOR EACH ROW EXECUTE FUNCTION year_end_records_guard();
            CREATE TRIGGER dividend_lines_guard BEFORE UPDATE OR DELETE ON dividend_lines
                FOR EACH ROW EXECUTE FUNCTION year_end_records_guard();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dividend_lines');
        Schema::dropIfExists('year_ends');
        DB::unprepared('DROP FUNCTION IF EXISTS year_end_records_guard() CASCADE');
    }
};
