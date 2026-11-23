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
    /**
     * Also inserted by the 2026_10_11_000003 migration, so a live install gets them on deploy.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paymentOutcomes(): array
    {
        return [
            SmsTemplateKey::PaymentRejected->value => [
                '{name}, আপনার {amount} পেমেন্ট গ্রহণ করা হয়নি। কারণ: {reason}। প্রশ্ন থাকলে অফিসে যোগাযোগ করুন। -{somiti}',
                '{name}, your payment of {amount} was not accepted. Reason: {reason}. Please contact the office with any question. -{somiti}',
            ],
            SmsTemplateKey::PaymentReversed->value => [
                '{name}, আপনার {amount} পেমেন্ট বাতিল করা হয়েছে। কারণ: {reason}। প্রশ্ন থাকলে অফিসে যোগাযোগ করুন। -{somiti}',
                '{name}, your payment of {amount} was cancelled. Reason: {reason}. Please contact the office with any question. -{somiti}',
            ],
        ];
    }

    /**
     * Also inserted by the 2026_11_23_000005 migration.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paymentRecorded(): array
    {
        return [
            SmsTemplateKey::PaymentRecorded->value => [
                '{name}, {date} তারিখে আপনার {amount} জমা নেওয়া হয়েছে, অনুমোদনের অপেক্ষায়। অনুমোদনের পর রসিদ এসএমএস পাবেন। -{somiti}',
                '{name}, {amount} taken on {date} is recorded and waiting for approval. You will get a receipt SMS once it is approved. -{somiti}',
            ],
        ];
    }

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
                '{name}, {amount} গ্রহণ করা হয়েছে, রসিদ {voucher}। পরিশোধিত: {paid_through} পর্যন্ত। বাকি: {owed}। -{somiti}',
                '{name}, {amount} received, receipt {voucher}. Paid through {paid_through}. Still owed: {owed}. -{somiti}',
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
            ...self::paymentOutcomes(),
            ...self::paymentRecorded(),
        ];

        foreach ($defaults as $key => [$bn, $en]) {
            SmsTemplate::query()->firstOrCreate(['key' => $key], ['body_bn' => $bn, 'body_en' => $en, 'is_active' => true]);
        }
    }
}
