<?php

declare(strict_types=1);

return [
    'title' => 'বার্ষিক সমাপনী',
    'status' => [
        'draft' => 'খসড়া — অনুমোদনের অপেক্ষায়',
        'posted' => 'পোস্ট হয়েছে',
    ],
    'dividend_status' => [
        'unpaid' => 'অপরিশোধিত',
        'paid' => 'পরিশোধিত',
        'credited' => 'সঞ্চয়ে জমা',
    ],
    'fund' => [
        'reserve' => 'সংরক্ষিত তহবিল',
        'development_fund' => 'সমবায় উন্নয়ন তহবিল',
        'bad_debt_fund' => 'অনাদায়ী ও সন্দেহজনক ঋণ তহবিল',
        'other_funds' => 'অন্যান্য তহবিল (উপআইন)',
    ],
    'errors' => [
        'rate_bounds' => ':fund নীট মুনাফার :min থেকে :max এর মধ্যে হতে হবে।',
        'over_appropriated' => 'বণ্টন ও ক্ষতি সমন্বয় মিলে নীট মুনাফার চেয়ে বেশি হয়েছে।',
        'no_shareholders' => 'বণ্টনযোগ্য লভ্যাংশ আছে, কিন্তু বছরে কোনো সদস্যের শেয়ার ছিল না।',
    ],
];
