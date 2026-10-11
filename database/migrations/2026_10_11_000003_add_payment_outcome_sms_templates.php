<?php

declare(strict_types=1);

use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * SMS to a member whose payment is rejected or reversed. Inserted only when missing, so an
     * edited template is never overwritten.
     */
    public function up(): void
    {
        foreach (SmsTemplateSeeder::paymentOutcomes() as $key => [$bn, $en]) {
            DB::table('sms_templates')->insertOrIgnore([
                'key' => $key,
                'body_bn' => $bn,
                'body_en' => $en,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('sms_templates')->whereIn('key', array_keys(SmsTemplateSeeder::paymentOutcomes()))->delete();
    }
};
