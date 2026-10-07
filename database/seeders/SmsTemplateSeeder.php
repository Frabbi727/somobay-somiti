<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Notifications\Enums\SmsTemplateKey;
use App\Domain\Notifications\Models\SmsTemplate;
use Illuminate\Database\Seeder;

/**
 * Default SMS texts; staff can edit them in Settings. Safe to run again (keeps edits).
 */
final class SmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            SmsTemplateKey::Welcome->value => [
                '{name}, {somiti}-এ স্বাগতম। আপনার সদস্য নং {member_no}। পোর্টাল: {portal_url}',
                'Welcome to {somiti}, {name}. Member no. {member_no}. Portal: {portal_url}',
            ],
            SmsTemplateKey::DuesGenerated->value => [
                '{name}, {month} মাসের কিস্তি {amount}, শেষ তারিখ {due_date}। -{somiti}',
                '{name}, your {month} instalment is {amount}, due by {due_date}. -{somiti}',
            ],
            SmsTemplateKey::PaymentApproved->value => [
                '{name}, {amount} গ্রহণ করা হয়েছে, রসিদ {voucher}। পরিশোধিত: {paid_through} পর্যন্ত। -{somiti}',
                '{name}, {amount} received, receipt {voucher}. Paid through {paid_through}. -{somiti}',
            ],
            SmsTemplateKey::LoginCode->value => [
                '{somiti} লগইন কোড: {code}। {minutes} মিনিট বৈধ। কাউকে জানাবেন না।',
                '{somiti} login code: {code}. Valid for {minutes} minutes. Do not share it.',
            ],
            SmsTemplateKey::RegistrationReturned->value => [
                '{somiti}: আপনার নিবন্ধন সংশোধনের জন্য ফেরত পাঠানো হয়েছে। কারণ: {reason}। অ্যাপ বা {portal_url} থেকে ঠিক করে আবার জমা দিন।',
                '{somiti}: your registration was sent back for correction. Reason: {reason}. Please fix it in the app or at {portal_url} and submit again.',
            ],
            SmsTemplateKey::RegistrationRejected->value => [
                '{somiti}: দুঃখিত, আপনার সদস্য নিবন্ধন গ্রহণ করা হয়নি। কারণ: {reason}। বিস্তারিত জানতে অফিসে যোগাযোগ করুন।',
                '{somiti}: sorry, your membership registration was not accepted. Reason: {reason}. Please contact the office.',
            ],
        ];

        foreach ($defaults as $key => [$bn, $en]) {
            SmsTemplate::query()->firstOrCreate(['key' => $key], ['body_bn' => $bn, 'body_en' => $en, 'is_active' => true]);
        }
    }
}
