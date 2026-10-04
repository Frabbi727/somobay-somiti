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
     * Investments (Phase 10): a register whose per-investment ledger ties to the 13xx accounts,
     * one account per kind of investment. Existing charts get the new accounts and 1301 becomes
     * the fixed-deposit control account.
     */
    public function up(): void
    {
        if (Account::query()->exists()) {
            (new ChartOfAccountsSeeder)->run();

            Account::query()->where('code', '1301')->update([
                'name_en' => ChartOfAccountsSeeder::ACCOUNTS['1301'][0],
                'name_bn' => ChartOfAccountsSeeder::ACCOUNTS['1301'][1],
                'is_control' => true,
            ]);
        }

        DB::statement('CREATE SEQUENCE IF NOT EXISTS investment_no_seq START 1');

        Schema::create('investments', function (Blueprint $table): void {
            $table->id();
            $table->string('investment_no', 12)->unique();
            $table->string('type', 24);
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('institution');
            $table->string('instrument_no', 60)->nullable();
            $table->unsignedBigInteger('principal_poisha');
            $table->string('funded_from', 10);
            $table->date('invested_on');
            $table->date('matures_on')->nullable();
            $table->unsignedInteger('expected_rate_bps')->nullable();
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('resolution_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('limit_warnings')->nullable();
            $table->string('status', 12)->default('pending');
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->date('closed_on')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
        });

        DB::statement("ALTER TABLE investments ADD CONSTRAINT investments_type_valid CHECK (type IN ('fixed_deposit', 'savings_certificate', 'government_securities', 'company_securities', 'cooperative', 'other'))");
        DB::statement("ALTER TABLE investments ADD CONSTRAINT investments_status_valid CHECK (status IN ('pending', 'active', 'rejected', 'cancelled', 'closed'))");
        DB::statement("ALTER TABLE investments ADD CONSTRAINT investments_funded_from_valid CHECK (funded_from IN ('cash', 'bank', 'bkash', 'nagad'))");
        DB::statement('ALTER TABLE investments ADD CONSTRAINT investments_principal_positive CHECK (principal_poisha > 0)');
        DB::statement('ALTER TABLE investments ADD CONSTRAINT investments_checker_differs CHECK (approved_by IS NULL OR approved_by <> recorded_by)');
        DB::statement("ALTER TABLE investments ADD CONSTRAINT investments_posted_when_approved CHECK ((status IN ('active', 'closed')) = (journal_entry_id IS NOT NULL))");
        DB::statement('ALTER TABLE investments ADD CONSTRAINT investments_maturity_after_start CHECK (matures_on IS NULL OR matures_on > invested_on)');

        Schema::create('investment_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('investment_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12);
            $table->bigInteger('delta_poisha');
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['investment_id', 'journal_entry_id']);
        });

        DB::statement("ALTER TABLE investment_ledger_entries ADD CONSTRAINT investment_ledger_kind_valid CHECK (kind IN ('disbursement', 'impairment', 'closure'))");
        DB::statement('ALTER TABLE investment_ledger_entries ADD CONSTRAINT investment_ledger_delta_nonzero CHECK (delta_poisha <> 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION investment_ledger_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Investment ledger entries are append-only.' USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER investment_ledger_append_only BEFORE UPDATE OR DELETE ON investment_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION investment_ledger_append_only();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investment_ledger_entries');
        Schema::dropIfExists('investments');
        DB::unprepared('DROP FUNCTION IF EXISTS investment_ledger_append_only() CASCADE');
        DB::statement('DROP SEQUENCE IF EXISTS investment_no_seq');
    }
};
