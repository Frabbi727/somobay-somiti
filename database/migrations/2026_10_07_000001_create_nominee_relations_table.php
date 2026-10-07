<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nominee relations as a list the committee keeps (member self-registration spec §5.1-5.2).
     * Old nominees keep their free-text relation; new and edited ones point at the list.
     */
    public function up(): void
    {
        Schema::create('nominee_relations', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('label_bn', 50);
            $table->string('label_en', 50);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE nominee_relations ADD CONSTRAINT nominee_relations_key_format CHECK (key ~ '^[a-z_]{2,30}$')");

        $now = now();

        foreach ([
            ['father', 'পিতা', 'Father'], ['mother', 'মাতা', 'Mother'], ['spouse', 'স্বামী/স্ত্রী', 'Spouse'],
            ['son', 'পুত্র', 'Son'], ['daughter', 'কন্যা', 'Daughter'], ['brother', 'ভাই', 'Brother'],
            ['sister', 'বোন', 'Sister'], ['other', 'অন্যান্য', 'Other'],
        ] as $index => [$key, $bn, $en]) {
            DB::table('nominee_relations')->insert([
                'key' => $key, 'label_bn' => $bn, 'label_en' => $en, 'sort' => ($index + 1) * 10,
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('nominees', function (Blueprint $table): void {
            $table->foreignId('relation_id')->nullable()->after('relation')->constrained('nominee_relations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nominees', fn (Blueprint $table) => $table->dropConstrainedForeignId('relation_id'));
        Schema::dropIfExists('nominee_relations');
    }
};
