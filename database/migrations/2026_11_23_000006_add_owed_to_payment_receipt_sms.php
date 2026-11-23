<?php

declare(strict_types=1);

use App\Domain\Notifications\Enums\SmsTemplateKey;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The receipt SMS before "still owed" was added. Only a row still holding these exact texts is
     * replaced, so a message the society edited is never overwritten.
     */
    private const array OLD = [
        'body_bn' => '{name}, {amount} গ্রহণ করা হয়েছে, রসিদ {voucher}। পরিশোধিত: {paid_through} পর্যন্ত। -{somiti}',
        'body_en' => '{name}, {amount} received, receipt {voucher}. Paid through {paid_through}. -{somiti}',
    ];

    public function up(): void
    {
        $key = SmsTemplateKey::PaymentApproved->value;
        [$bn, $en] = SmsTemplateSeeder::defaults()[$key];

        DB::table('sms_templates')->where('key', $key)->where('body_bn', self::OLD['body_bn'])->update(['body_bn' => $bn, 'updated_at' => now()]);
        DB::table('sms_templates')->where('key', $key)->where('body_en', self::OLD['body_en'])->update(['body_en' => $en, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $key = SmsTemplateKey::PaymentApproved->value;
        [$bn, $en] = SmsTemplateSeeder::defaults()[$key];

        DB::table('sms_templates')->where('key', $key)->where('body_bn', $bn)->update(['body_bn' => self::OLD['body_bn'], 'updated_at' => now()]);
        DB::table('sms_templates')->where('key', $key)->where('body_en', $en)->update(['body_en' => self::OLD['body_en'], 'updated_at' => now()]);
    }
};
