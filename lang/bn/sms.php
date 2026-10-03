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
    ],
    'log' => [
        'singular' => 'এসএমএস',
        'plural' => 'এসএমএস লগ',
        'at' => 'সময়',
        'to' => 'প্রাপক',
        'body' => 'বার্তা',
        'status' => 'অবস্থা',
    ],
    'status' => [
        'queued' => 'অপেক্ষমাণ',
        'sent' => 'পাঠানো হয়েছে',
        'failed' => 'ব্যর্থ',
    ],
    'errors' => [
        'body_required' => 'দুটি লেখাই আবশ্যক।',
        'unknown_placeholder' => ':placeholder এই বার্তায় ব্যবহার করা যায় না।',
    ],
];
