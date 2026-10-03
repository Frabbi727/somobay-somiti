<?php

declare(strict_types=1);

return [
    'singular' => 'কর্মী ব্যবহারকারী',
    'plural' => 'কর্মী ব্যবহারকারী',
    'field' => [
        'name' => 'নাম',
        'email' => 'ইমেইল',
        'mobile' => 'মোবাইল',
        'mobile_help' => 'হিসাব যাচাইয়ের সতর্কবার্তা এই নম্বরে SMS-এ যাবে।',
        'locale' => 'ভাষা',
        'roles' => 'দায়িত্ব',
        'mfa' => 'অথেন্টিকেটর অ্যাপ',
        'status' => 'অবস্থা',
        'password' => 'পাসওয়ার্ড',
        'new_password' => 'নতুন পাসওয়ার্ড',
        'new_password_help' => 'বর্তমান পাসওয়ার্ড রাখতে খালি রাখুন।',
    ],
    'status' => [
        'active' => 'সক্রিয়',
        'inactive' => 'নিষ্ক্রিয়',
    ],
    'actions' => [
        'create' => 'নতুন কর্মী ব্যবহারকারী',
        'create_heading' => ':name এর অ্যাকাউন্ট তৈরি করবেন?',
        'save_heading' => ':name এর পরিবর্তন সংরক্ষণ করবেন?',
        'deactivate' => 'নিষ্ক্রিয় করুন',
        'deactivate_heading' => ':name কে নিষ্ক্রিয় করবেন?',
        'deactivate_description' => 'তিনি আর লগইন করতে পারবেন না। তাঁর করা সব এন্ট্রি তাঁর নামেই থাকবে।',
        'reactivate' => 'পুনরায় সক্রিয় করুন',
        'reactivate_heading' => ':name কে আবার লগইন করতে দেবেন?',
    ],
    'notifications' => [
        'created' => 'কর্মীর অ্যাকাউন্ট তৈরি হয়েছে।',
        'saved' => ':name সংরক্ষিত হয়েছে।',
        'deactivated' => ':name আর লগইন করতে পারবেন না।',
        'reactivated' => ':name আবার লগইন করতে পারবেন।',
    ],
    'errors' => [
        'name_required' => 'নাম লিখুন।',
        'email_invalid' => 'সঠিক ইমেইল ঠিকানা লিখুন।',
        'email_taken' => ':email অন্য একটি অ্যাকাউন্টে ব্যবহৃত হচ্ছে।',
        'mobile_invalid' => 'সঠিক বাংলাদেশি মোবাইল নম্বর লিখুন (01XXXXXXXXX)।',
        'staff_role_required' => 'অন্তত একটি কর্মী দায়িত্ব বেছে নিন ("সদস্য" নয়)।',
        'password_required' => 'নতুন অ্যাকাউন্টের পাসওয়ার্ড দিন।',
        'password_short' => 'পাসওয়ার্ড অন্তত :min অক্ষরের হতে হবে।',
        'last_super_admin' => 'অন্তত একজন সক্রিয় সুপার এডমিন থাকতে হবে।',
        'cannot_deactivate_self' => 'নিজের অ্যাকাউন্ট নিষ্ক্রিয় করা যায় না।',
    ],
];
