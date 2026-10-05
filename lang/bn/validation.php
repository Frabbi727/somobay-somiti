<?php

declare(strict_types=1);

/*
| বাংলা যাচাই-বার্তা (Laravel-এর নিয়মগুলোর জন্য) ও ঘরের নাম।
*/

return [
    'accepted' => ':attribute গ্রহণ করতে হবে।',
    'after_or_equal' => ':attribute অবশ্যই :date বা তার পরের তারিখ হতে হবে।',
    'array' => ':attribute একটি তালিকা হতে হবে।',
    'before_or_equal' => ':attribute অবশ্যই :date বা তার আগের তারিখ হতে হবে।',
    'boolean' => ':attribute হ্যাঁ বা না হতে হবে।',
    'confirmed' => ':attribute নিশ্চিতকরণের সাথে মিলছে না।',
    'date' => ':attribute সঠিক তারিখ নয়।',
    'date_format' => ':attribute অবশ্যই :format আকারে হতে হবে।',
    'different' => ':attribute ও :other ভিন্ন হতে হবে।',
    'digits' => ':attribute অবশ্যই :digits অঙ্কের হতে হবে।',
    'email' => ':attribute সঠিক ইমেইল ঠিকানা নয়।',
    'enum' => 'নির্বাচিত :attribute সঠিক নয়।',
    'file' => ':attribute একটি ফাইল হতে হবে।',
    'image' => ':attribute একটি ছবি হতে হবে।',
    'in' => 'নির্বাচিত :attribute সঠিক নয়।',
    'integer' => ':attribute একটি পূর্ণসংখ্যা হতে হবে।',
    'max' => [
        'array' => ':attribute-এ :max-টির বেশি থাকতে পারবে না।',
        'file' => ':attribute :max কিলোবাইটের বেশি হতে পারবে না।',
        'numeric' => ':attribute :max-এর বেশি হতে পারবে না।',
        'string' => ':attribute :max অক্ষরের বেশি হতে পারবে না।',
    ],
    'mimes' => ':attribute অবশ্যই এই ধরনের ফাইল হতে হবে: :values।',
    'mimetypes' => ':attribute অবশ্যই এই ধরনের ফাইল হতে হবে: :values।',
    'min' => [
        'array' => ':attribute-এ অন্তত :min-টি থাকতে হবে।',
        'file' => ':attribute অন্তত :min কিলোবাইট হতে হবে।',
        'numeric' => ':attribute অন্তত :min হতে হবে।',
        'string' => ':attribute অন্তত :min অক্ষরের হতে হবে।',
    ],
    'numeric' => ':attribute একটি সংখ্যা হতে হবে।',
    'regex' => ':attribute-এর গঠন সঠিক নয়।',
    'required' => ':attribute ঘরটি পূরণ করা আবশ্যক।',
    'required_with' => ':values দেওয়া হলে :attribute ঘরটি পূরণ করা আবশ্যক।',
    'required_without' => ':values না দিলে :attribute ঘরটি পূরণ করা আবশ্যক।',
    'string' => ':attribute লেখা (টেক্সট) হতে হবে।',
    'unique' => 'এই :attribute আগেই ব্যবহার হয়েছে।',
    'uploaded' => ':attribute আপলোড করা যায়নি।',
    'uuid' => ':attribute সঠিক নয়।',

    'attributes' => [
        'mobile' => 'মোবাইল নম্বর',
        'password' => 'পাসওয়ার্ড',
        'current_password' => 'বর্তমান পাসওয়ার্ড',
        'password_confirmation' => 'পাসওয়ার্ড নিশ্চিতকরণ',
        'code' => 'কোড',
        'refresh_token' => 'রিফ্রেশ টোকেন',
        'method' => 'পরিশোধের মাধ্যম',
        'amount' => 'টাকার পরিমাণ',
        'trx_id' => 'লেনদেন নম্বর (TrxID)',
        'received_on' => 'পরিশোধের তারিখ',
        'proof' => 'পরিশোধের প্রমাণ',
        'idempotency_key' => 'জমার কী',
        'status' => 'অবস্থা',
        'type' => 'ধরন',
        'from' => 'শুরুর তারিখ',
        'until' => 'শেষ তারিখ',
        'page' => 'পাতা',
    ],
];
