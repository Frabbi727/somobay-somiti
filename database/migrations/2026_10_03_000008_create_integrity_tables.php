<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Results of the §6.7 integrity checks (nightly and on demand), plus staff mobiles for alerts.
     */
    public function up(): void
    {
        Schema::create('integrity_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 10);
            $table->unsignedSmallInteger('checks_run')->default(0);
            $table->unsignedInteger('findings_count')->default(0);
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->index(['status', 'id']);
        });

        DB::statement("ALTER TABLE integrity_runs ADD CONSTRAINT integrity_runs_status_valid CHECK (status IN ('running', 'passed', 'failed'))");

        Schema::create('integrity_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integrity_run_id')->constrained()->cascadeOnDelete();
            $table->string('check', 40);
            $table->text('message');
            $table->jsonb('context')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('mobile', 11)->nullable()->after('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('mobile');
        });
        Schema::dropIfExists('integrity_findings');
        Schema::dropIfExists('integrity_runs');
    }
};
