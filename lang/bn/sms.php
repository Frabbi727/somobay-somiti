<?php

declare(strict_types=1);

return [
    'length' => ':chars অক্ষর · :encoding · :parts টি এসএমএস',
    'template' => [
        'singular' => 'এসএমএস টেমপ্লেট',
        'plural' => 'এসএমএস টেমপ্লেট',
        'key' => 'বার্তা',
        'placeholders' => 'যে স্থানধারক ব্যবহার করা যাবে',
        'body_bn' => 'লেখা (বাংলা)',
        'body_en' => 'লেখা (ইংরেজি)',
        'is_active' => 'এই বার্তা পাঠানো হবে',
        'parts' => 'অংশ',
        'save_heading' => '":key" বার্তা সংরক্ষণ করবেন?',
        'welcome' => 'স্বাগতম',
        'dues_generated' => 'মাসিক কিস্তির নোটিশ',
        'payment_approved' => 'টাকা প্রাপ্তি',
        'login_code' => 'পোর্টাল লগইন কোড',
        'registration_returned' => 'নিবন্ধন ফেরত',
        'registration_rejected' => 'নিবন্ধন বাতিল',
        'payment_rejected' => 'পেমেন্ট গৃহীত হয়নি',
        'payment_reversed' => 'পেমেন্ট বাতিল',
    ],
    'log' => [
        'singular' => 'এসএমএস',
        'plural' => 'এসএমএস লগ',
        'at' => 'সময়',
        'to' => 'প্রাপক',
        'body' => 'বার্তা',
        'status' => 'অবস্থা',
        'resend' => 'আবার পাঠান',
        'resend_help' => 'ব্যর্থ বার্তাটি আবার পাঠানোর চেষ্টা করুন, যেমন এসএমএস ব্যালেন্স রিচার্জের পর',
        'resend_heading' => ':to নম্বরে এই বার্তা আবার পাঠাবেন?',
        'resent' => 'বার্তাটি আবার পাঠানোর জন্য সারিতে রাখা হয়েছে।',
    ],
    'status' => [
        'queued' => 'অপেক্ষমাণ',
        'sent' => 'পাঠানো হয়েছে',
        'failed' => 'ব্যর্থ',
    ],
    'errors' => [
        'body_required' => 'দুটি লেখাই আবশ্যক।',
        'not_failed' => 'শুধু ব্যর্থ বার্তা আবার পাঠানো যায়। পাতাটি রিফ্রেশ করে বর্তমান অবস্থা দেখুন।',
        'unknown_placeholder' => ':placeholder এই বার্তায় ব্যবহার করা যায় না।',
    ],
];
