<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SMS templates (editable in Settings) and the log of every message (SOMITI_SPEC.md P6.S2).
     */
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->text('body_bn');
            $table->text('body_en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('to', 11);
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_key', 40)->nullable();
            $table->string('dedupe_key', 120)->nullable()->unique();
            $table->text('body');
            $table->unsignedSmallInteger('segments');
            $table->string('status', 10)->default('queued');
            $table->string('provider', 20)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->nullableMorphs('related');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE sms_messages ADD CONSTRAINT sms_messages_status_valid CHECK (status IN ('queued', 'sent', 'failed'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_templates');
    }
};
