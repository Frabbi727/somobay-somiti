<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['members', 'member_applications'] as $tableName) {
            $this->addPersonColumns($tableName);

            Schema::table($tableName, function (Blueprint $table): void {
                $table->jsonb('documents_received')->default('[]');
            });
        }

        foreach (['nominees', 'member_application_nominees'] as $tableName) {
            $this->addPersonColumns($tableName);
        }
    }

    private function addPersonColumns(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('gender', 6)->nullable();
            $table->string('passport_no', 20)->nullable();
            $table->unsignedInteger('present_upazila_id')->nullable();
            $table->unsignedInteger('present_union_id')->nullable();
            $table->string('present_address_line', 255)->nullable();
            $table->unsignedInteger('permanent_upazila_id')->nullable();
            $table->unsignedInteger('permanent_union_id')->nullable();
            $table->string('permanent_address_line', 255)->nullable();

            $table->foreign('present_upazila_id')->references('id')->on('geo_upazilas')->restrictOnDelete();
            $table->foreign('present_union_id')->references('id')->on('geo_unions')->restrictOnDelete();
            $table->foreign('permanent_upazila_id')->references('id')->on('geo_upazilas')->restrictOnDelete();
            $table->foreign('permanent_union_id')->references('id')->on('geo_unions')->restrictOnDelete();
        });

        DB::unprepared(match ($tableName) {
            'members' => <<<'SQL'
            ALTER TABLE members
                ADD CONSTRAINT members_gender_valid CHECK (gender IS NULL OR gender IN ('male', 'female', 'other')),
                ADD CONSTRAINT members_passport_format CHECK (passport_no IS NULL OR passport_no ~ '^[A-Z0-9]{6,20}$');
            SQL,
            'member_applications' => <<<'SQL'
            ALTER TABLE member_applications
                ADD CONSTRAINT member_applications_gender_valid CHECK (gender IS NULL OR gender IN ('male', 'female', 'other')),
                ADD CONSTRAINT member_applications_passport_format CHECK (passport_no IS NULL OR passport_no ~ '^[A-Z0-9]{6,20}$');
            SQL,
            'nominees' => <<<'SQL'
            ALTER TABLE nominees
                ADD CONSTRAINT nominees_gender_valid CHECK (gender IS NULL OR gender IN ('male', 'female', 'other')),
                ADD CONSTRAINT nominees_passport_format CHECK (passport_no IS NULL OR passport_no ~ '^[A-Z0-9]{6,20}$');
            SQL,
            'member_application_nominees' => <<<'SQL'
            ALTER TABLE member_application_nominees
                ADD CONSTRAINT member_application_nominees_gender_valid CHECK (gender IS NULL OR gender IN ('male', 'female', 'other')),
                ADD CONSTRAINT member_application_nominees_passport_format CHECK (passport_no IS NULL OR passport_no ~ '^[A-Z0-9]{6,20}$');
            SQL,
            default => throw new InvalidArgumentException($tableName),
        });
    }
};
