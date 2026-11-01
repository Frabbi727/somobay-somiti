<?php

declare(strict_types=1);

use App\Domain\Geo\Services\GeoCsvLoader;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_divisions', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name_en');
            $table->string('name_bn');
        });

        Schema::create('geo_districts', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('division_id')->index();
            $table->string('name_en');
            $table->string('name_bn');
            $table->foreign('division_id')->references('id')->on('geo_divisions')->restrictOnDelete();
        });

        Schema::create('geo_upazilas', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('district_id')->index();
            $table->string('name_en');
            $table->string('name_bn');
            $table->foreign('district_id')->references('id')->on('geo_districts')->restrictOnDelete();
        });

        Schema::create('geo_unions', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('upazila_id')->index();
            $table->string('name_en');
            $table->string('name_bn');
            $table->foreign('upazila_id')->references('id')->on('geo_upazilas')->restrictOnDelete();
        });

        (new GeoCsvLoader)->load(database_path('demrcation'));
    }
};
